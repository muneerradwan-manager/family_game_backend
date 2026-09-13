<?php

namespace App\Http\Controllers\Api;

use App\Events\ChannelEvent;
use App\Games\GameModule;
use App\Games\GameModuleRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Jobs\RemindLobbyHost;
use App\Models\Channel;
use App\Models\Game;
use App\Notifications\PushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * طبقة المنصّة للألعاب: فتح الغرفة، اللوبي، البدء، اللقطة، الإنهاء.
 *
 * لا يوجد هنا أي منطق خاص بلعبة بعينها — كل ذلك خلف GameModule. إضافة لعبة
 * جديدة لا تلمس هذا الملف.
 */
class GameController extends Controller
{
    public function __construct(
        private readonly GameModuleRegistry $registry,
        private readonly PushNotifier $push,
    ) {}

    /** شاشة "اختيار اللعبة" وشاشة الشروط تُبنيان من هنا. */
    public function catalog(): JsonResponse
    {
        $games = [];

        foreach ($this->registry->all() as $module) {
            $games[] = [
                'gameType' => $module->type(),
                'name' => $module->name(),
                'icon' => $module->icon(),
                'description' => $module->description(),
                'minPlayers' => $module->minPlayers(),
                'maxPlayers' => $module->maxPlayers(),
                'configSchema' => $module->configSchema(),
            ];
        }

        return response()->json(['games' => $games]);
    }

    /**
     * "افتح الغرفة".
     *
     * قاعدة "لعبة نشطة واحدة لكل قناة" تُفرَض هنا بـ transaction وتحديث شرطي:
     * ينجح فقط إذا كان active_game_id فارغاً — فلا تنشأ غرفتان معاً مهما
     * تزامن ضغط زرّين.
     */
    public function store(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeMember($request, $channel);

        $data = $request->validate([
            'gameType' => ['required', 'string', Rule::in(array_keys($this->registry->all()))],
            'config' => ['sometimes', 'array'],
        ]);

        $module = $this->registry->get($data['gameType']);
        $config = $module->normalizeConfig($data['config'] ?? []);
        $user = $request->user();

        $game = DB::transaction(function () use ($channel, $module, $config, $user) {
            $locked = Channel::whereKey($channel->id)->lockForUpdate()->first();

            if ($locked->active_game_id !== null) {
                throw ValidationException::withMessages([
                    'gameType' => 'في لعبة جارية بالقناة — استنى تخلص أو انضم إلها.',
                ]);
            }

            $game = Game::create([
                'channel_id' => $channel->id,
                'game_type' => $module->type(),
                'status' => Game::STATUS_LOBBY,
                'config' => $config,
                'started_by' => $user->id,
            ]);

            $claimed = Channel::whereKey($channel->id)
                ->whereNull('active_game_id')
                ->update(['active_game_id' => $game->id]);

            if ($claimed === 0) {
                throw ValidationException::withMessages([
                    'gameType' => 'في لعبة جارية بالقناة.',
                ]);
            }

            DB::table('game_players')->insert([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'join_order' => 1,
                'is_spectator' => false,
            ]);

            return $game;
        });

        $module->openLobby($game, $user);

        ChannelEvent::dispatch($channel->id, 'active_game_changed', [
            'activeGame' => [
                'id' => $game->id,
                'gameType' => $game->game_type,
                'status' => $game->status,
                'startedBy' => $user->id,
                'startedByUsername' => $user->username,
            ],
        ]);

        // بعد 5 دقائق بلا بدء: تذكير لصاحب الغرفة. غرفة منسيّة تحجز القناة كلها.
        RemindLobbyHost::dispatch($game->id)->delay(now()->addMinutes(5));

        // FCM للدعوة فقط — التزامن كله عبر القناة الحيّة.
        $this->push->toChannelMembers(
            $channel,
            except: [$user->id],
            title: $channel->name,
            body: $user->username.' بلّش '.$module->name().' — انضم!',
            data: ['type' => 'game_invite', 'gameId' => $game->id, 'channelId' => $channel->id],
        );

        return response()->json([
            'game' => new GameResource($game),
            'state' => $module->snapshot($game, $user),
        ], 201);
    }

    /** لقطة كاملة — تُستخدم عند الدخول وعند إعادة الاتصال بعد انقطاع. */
    public function show(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        return response()->json([
            'game' => new GameResource($game->load('starter')),
            'state' => $this->module($game)->snapshot($game, $request->user()),
        ]);
    }

    public function join(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);
        $this->assertLive($game);

        $module = $this->module($game);
        $user = $request->user();

        $module->join($game, $user);

        return response()->json(['state' => $module->snapshot($game, $user)]);
    }

    public function leave(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $this->module($game)->leave($game, $request->user());

        return response()->json(['message' => 'غادرت اللعبة.']);
    }

    public function start(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);
        $this->assertLive($game);

        $this->module($game)->start($game, $request->user());

        return response()->json(['state' => $this->module($game)->snapshot($game, $request->user())]);
    }

    public function endEarly(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $this->module($game)->endEarly($game, $request->user());

        return response()->json(['message' => 'تم الطلب.']);
    }

    private function module(Game $game): GameModule
    {
        return $this->registry->get($game->game_type);
    }

    private function assertLive(Game $game): void
    {
        abort_unless($game->isLive(), 409, 'هذه اللعبة انتهت.');
    }

    private function authorizeMember(Request $request, Channel $channel): void
    {
        abort_unless(
            $channel->members()->where('users.id', $request->user()->id)->exists(),
            403,
            'لست عضواً في هذه القناة.',
        );
    }

    private function authorizeChannelMember(Request $request, Game $game): void
    {
        abort_unless(
            DB::table('channel_members')
                ->where('channel_id', $game->channel_id)
                ->where('user_id', $request->user()->id)
                ->exists(),
            403,
            'لست عضواً في قناة هذه اللعبة.',
        );
    }
}
