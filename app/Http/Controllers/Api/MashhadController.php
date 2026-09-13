<?php

namespace App\Http\Controllers\Api;

use App\Games\Mashhad\MashhadEngine;
use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * نوايا لعبة المشهد.
 *
 * تصل عبر REST كبقية الألعاب، والردّ إقرار استلام (202): الرسالة تصل للجميع
 * معاً عبر القناة الحيّة لا في ردّ صاحبها، فلا يسبق المُرسِل غيره برؤيتها.
 */
class MashhadController extends Controller
{
    public function __construct(private readonly MashhadEngine $engine) {}

    /** رسالة داخل المشهد. */
    public function say(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:400'],
        ]);

        $this->engine->say($game, $request->user(), $data['text']);

        return $this->accepted();
    }

    /** ادّعاء ما حقّقته بعد نهاية المشهد. */
    public function claim(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'main' => ['required', 'boolean'],
            'bonus' => ['sometimes', 'boolean'],
            'event' => ['sometimes', 'boolean'],
        ]);

        $this->engine->claim(
            $game,
            $request->user(),
            (bool) $data['main'],
            (bool) ($data['bonus'] ?? false),
            (bool) ($data['event'] ?? false),
        );

        return $this->accepted();
    }

    /** اعتراض على ادّعاء لاعب آخر. */
    public function challenge(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'targetUserId' => ['required', 'uuid'],
            'kind' => ['required', 'string', 'in:main,bonus,event'],
        ]);

        $this->engine->challenge($game, $request->user(), $data['targetUserId'], $data['kind']);

        return $this->accepted();
    }

    /** تصويت على الاعتراض المعروض. */
    public function vote(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'challengeId' => ['required', 'uuid'],
            'achieved' => ['required', 'boolean'],
        ]);

        $this->engine->vote($game, $request->user(), $data['challengeId'], (bool) $data['achieved']);

        return $this->accepted();
    }

    /** تصويت جائزة نهاية المباراة. */
    public function award(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'awardKey' => ['required', 'string', 'max:20'],
            'targetUserId' => ['required', 'uuid'],
        ]);

        $this->engine->awardVote($game, $request->user(), $data['awardKey'], $data['targetUserId']);

        return $this->accepted();
    }

    /**
     * المجموعات والمدد والنقاط — يقرأها التطبيق مرة ويخزّنها.
     *
     * المشاهد نفسها ليست هنا: من يحمل بنك المشاهد يعرف أهداف زملائه قبل
     * أن تُوزَّع.
     */
    public function reference(): JsonResponse
    {
        $counts = DB::table('mashhad_scenes')
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $categories = [];

        foreach (config('mashhad.categories') as $key => $category) {
            $categories[] = [
                'key' => $key,
                'label' => $category['label'],
                'emoji' => $category['emoji'],
                'sceneCount' => (int) ($counts[$key] ?? 0),
            ];
        }

        return response()->json([
            'categories' => $categories,
            'awards' => config('mashhad.awards'),
            'phases' => config('mashhad.phases'),
            'points' => config('mashhad.points'),
            'limits' => config('mashhad.limits'),
            'scenesOptions' => config('mashhad.scenes_options'),
            'sceneSecondsOptions' => config('mashhad.scene_seconds_options'),
        ]);
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['accepted' => true], 202);
    }

    private function authorizeChannelMember(Request $request, Game $game): void
    {
        abort_unless($game->game_type === MashhadEngine::TYPE, 404);

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
