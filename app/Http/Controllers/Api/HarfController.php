<?php

namespace App\Http\Controllers\Api;

use App\Games\Harf\HarfConfig;
use App\Games\Harf\HarfEngine;
use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * نوايا لعبة الحروف.
 *
 * تصل عبر REST لا عبر رسائل WebSocket صاعدة: بذلك تمر كل نيّة بنفس طبقة
 * المصادقة والتحقق ورَتل الطلبات، والقناة الحيّة تبقى ذات اتجاه واحد —
 * السيرفر يبثّ الحقيقة والأجهزة تعرضها.
 *
 * الردّ هنا مجرد إقرار استلام (202): النتيجة الفعلية تصل للجميع معاً عبر
 * القناة الحيّة، لا في ردّ الطلب — وإلا سبق صاحب الطلب غيره بجزء من الثانية.
 */
class HarfController extends Controller
{
    public function __construct(private readonly HarfEngine $engine) {}

    /** الجهاز يرسل "طلب سحب" فقط — السيرفر هو من يسحب الحرف دائماً. */
    public function drawLetter(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);
        $this->engine->drawLetter($game, $request->user());

        return $this->accepted();
    }

    /** الإجابات تُرسَل مع كل تغيير: تحمي من انقطاع النت وتمنع الإرسال بعد الإقفال. */
    public function updateAnswer(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'column' => ['required', 'string', 'max:30'],
            'text' => ['present', 'string', 'max:60'],
        ]);

        $this->engine->updateAnswer($game, $request->user(), $data['column'], $data['text']);

        return $this->accepted();
    }

    public function pressStop(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);
        $this->engine->pressStop($game, $request->user());

        return $this->accepted();
    }

    public function raiseObjection(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'targetUserId' => ['required', 'uuid'],
            'column' => ['required', 'string', 'max:30'],
        ]);

        $this->engine->raiseObjection($game, $request->user(), $data['targetUserId'], $data['column']);

        return $this->accepted();
    }

    public function castVote(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'objectionId' => ['required', 'uuid'],
            'valid' => ['required', 'boolean'],
        ]);

        $this->engine->castVote($game, $request->user(), $data['objectionId'], (bool) $data['valid']);

        return $this->accepted();
    }

    public function submitTiebreak(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:60'],
        ]);

        $this->engine->submitTiebreak($game, $request->user(), $data['text']);

        return $this->accepted();
    }

    /** الأعمدة والحروف والنقاط — يقرأها التطبيق مرة ويخزّنها. */
    public function reference(): JsonResponse
    {
        return response()->json([
            'letters' => config('harf.letters'),
            'columns' => config('harf.columns'),
            'flexibleColumns' => config('harf.flexible_columns'),
            'sixthColumns' => config('harf.sixth_columns'),
            'sixthColumnKeys' => HarfConfig::sixthColumnKeys(),
            'phases' => config('harf.phases'),
            'points' => config('harf.points'),
            'limits' => config('harf.limits'),
            'writeSecondsOptions' => config('harf.write_seconds_options'),
            'roundsPerPlayerOptions' => config('harf.rounds_per_player_options'),
        ]);
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['accepted' => true], 202);
    }

    private function authorizeChannelMember(Request $request, Game $game): void
    {
        abort_unless($game->game_type === HarfEngine::TYPE, 404);

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
