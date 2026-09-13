<?php

namespace App\Http\Controllers\Api;

use App\Events\ChannelEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\GameResource;
use App\Models\Channel;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * القنوات: المجموعة المغلقة التي تجري داخلها الألعاب.
 *
 * وهي الحل البنيوي لمشكلة الإشعارات — إشعار "بدأ لعبة" يصل لأعضاء القناة
 * فقط، فلا سبام على مستوى التطبيق أبداً.
 */
class ChannelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $channels = $request->user()->channels()
            ->withCount('members')
            ->with(['activeGame' => fn ($query) => $query->withCount('players')])
            ->orderByPivot('joined_at', 'desc')
            ->get();

        return response()->json(['channels' => ChannelResource::collection($channels)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'photoUrl' => ['nullable', 'url', 'max:255'],
        ]);

        $user = $request->user();

        $channel = DB::transaction(function () use ($data, $user) {
            $channel = Channel::create([
                'name' => $data['name'],
                'photo_url' => $data['photoUrl'] ?? null,
                'owner_id' => $user->id,
                'invite_code' => Channel::generateInviteCode(),
            ]);

            $channel->members()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

            return $channel;
        });

        return response()->json([
            'channel' => new ChannelResource($channel->loadCount('members')->load('members')),
        ], 201);
    }

    public function show(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeMember($request, $channel);

        $channel->loadCount('members')->load([
            'members',
            'activeGame' => fn ($query) => $query->withCount('players')->with('starter'),
        ]);

        return response()->json(['channel' => new ChannelResource($channel)]);
    }

    public function update(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeOwner($request, $channel);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:60'],
            'photoUrl' => ['nullable', 'url', 'max:255'],
        ]);

        if (isset($data['name'])) {
            $channel->name = $data['name'];
        }

        if ($request->exists('photoUrl')) {
            $channel->photo_url = $data['photoUrl'] ?? null;
        }

        $channel->save();

        ChannelEvent::dispatch($channel->id, 'channel_updated', [
            'name' => $channel->name,
            'photoUrl' => $channel->photo_url,
        ]);

        return response()->json(['channel' => new ChannelResource($channel->loadCount('members'))]);
    }

    /** الانضمام برمز الدعوة — بلا موافقة مسبقة: قناة عائلة لا منتدى عام. */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inviteCode' => ['required', 'string', 'size:6'],
        ]);

        $channel = Channel::where('invite_code', strtoupper($data['inviteCode']))->first();

        if (! $channel) {
            throw ValidationException::withMessages(['inviteCode' => 'رمز الدعوة غير صحيح.']);
        }

        $user = $request->user();

        if ($channel->members()->where('users.id', $user->id)->exists()) {
            return response()->json([
                'channel' => new ChannelResource($channel->loadCount('members')),
                'message' => 'أنت عضو في هذه القناة أصلاً.',
            ]);
        }

        if ($channel->members()->count() >= Channel::MAX_MEMBERS) {
            throw ValidationException::withMessages([
                'inviteCode' => 'القناة وصلت الحد الأقصى ('.Channel::MAX_MEMBERS.' عضواً).',
            ]);
        }

        $channel->members()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);

        ChannelEvent::dispatch($channel->id, 'member_joined', [
            'userId' => $user->id,
            'username' => $user->username,
        ]);

        return response()->json([
            'channel' => new ChannelResource($channel->loadCount('members')->load('members')),
        ]);
    }

    /** إعادة توليد الرمز — لإبطال رابط قديم إن تسرّب. */
    public function regenerateInviteCode(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeOwner($request, $channel);

        $channel->forceFill(['invite_code' => Channel::generateInviteCode()])->save();

        return response()->json(['inviteCode' => $channel->invite_code]);
    }

    public function removeMember(Request $request, Channel $channel, string $userId): JsonResponse
    {
        $this->authorizeOwner($request, $channel);

        if ($userId === $channel->owner_id) {
            throw ValidationException::withMessages(['userId' => 'لا يمكن إزالة مالك القناة.']);
        }

        $channel->members()->detach($userId);

        ChannelEvent::dispatch($channel->id, 'member_removed', ['userId' => $userId]);

        return response()->json(['message' => 'تمت إزالة العضو.']);
    }

    /** مغادرة المالك: الملكية تنتقل تلقائياً لأقدم عضو منضم. */
    public function leave(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeMember($request, $channel);

        $user = $request->user();

        DB::transaction(function () use ($channel, $user) {
            $channel->members()->detach($user->id);

            if ($channel->owner_id !== $user->id) {
                return;
            }

            $successor = $channel->members()->first();

            if ($successor === null) {
                $channel->delete();

                return;
            }

            $channel->forceFill(['owner_id' => $successor->id])->save();
            $channel->members()->updateExistingPivot($successor->id, ['role' => 'owner']);
        });

        ChannelEvent::dispatch($channel->id, 'member_left', ['userId' => $user->id]);

        return response()->json(['message' => 'غادرت القناة.']);
    }

    /** سجل الألعاب: آخر النتائج — أساس "بطولة العيلة" لاحقاً. */
    public function history(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeMember($request, $channel);

        $games = $channel->games()
            ->where('status', Game::STATUS_FINISHED)
            ->with('starter')
            ->withCount('players')
            ->orderByDesc('finished_at')
            ->limit(50)
            ->get();

        return response()->json(['games' => GameResource::collection($games)]);
    }

    private function authorizeMember(Request $request, Channel $channel): void
    {
        abort_unless(
            $channel->members()->where('users.id', $request->user()->id)->exists(),
            403,
            'لست عضواً في هذه القناة.',
        );
    }

    private function authorizeOwner(Request $request, Channel $channel): void
    {
        abort_unless($channel->isOwner($request->user()), 403, 'هذه الصلاحية لمالك القناة.');
    }
}
