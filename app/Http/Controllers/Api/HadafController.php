<?php

namespace App\Http\Controllers\Api;

use App\Games\Hadaf\HadafEngine;
use App\Games\Hadaf\Questions\QuestionPool;
use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * نوايا لعبة الهدف.
 *
 * نيّة واحدة فقط أثناء اللعب — الإجابة — وهي أحسّ نقطة في التطبيق كله:
 * اللعبة تُحسم بالمللي ثانية، فالجهاز يرسل رقم الخيار **بلا توقيت**،
 * والسيرفر يختم لحظة الوصول. لو قُبل ختم الجهاز لفاز أسرع من يعدّل حزمة
 * لا أسرع من يفكّر.
 */
class HadafController extends Controller
{
    public function __construct(private readonly HadafEngine $engine) {}

    public function answer(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'choice' => ['required', 'integer', 'min:0', 'max:9'],
            'risk' => ['sometimes', 'boolean'],
        ]);

        $this->engine->answer(
            $game,
            $request->user(),
            (int) $data['choice'],
            (bool) ($data['risk'] ?? false),
        );

        return response()->json(['accepted' => true], 202);
    }

    /**
     * المجموعات والمدد والنقاط — يقرأها التطبيق مرة ويخزّنها.
     *
     * الأسئلة نفسها ليست هنا بالطبع.
     */
    public function reference(QuestionPool $pool): JsonResponse
    {
        $counts = $pool->bankCounts();
        $categories = [];

        foreach (config('hadaf.categories') as $key => $category) {
            $categories[] = [
                'key' => $key,
                'label' => $category['label'],
                'emoji' => $category['emoji'],
                'generated' => (bool) $category['generated'],
                // المولَّدة لا عدد لها: بنكها لا نهائي.
                'bankCount' => $category['generated'] ? null : ($counts[$key] ?? 0),
            ];
        }

        return response()->json([
            'categories' => $categories,
            'difficulties' => config('hadaf.difficulties'),
            'phases' => config('hadaf.phases'),
            'points' => config('hadaf.points'),
            'limits' => config('hadaf.limits'),
            'roundsOptions' => config('hadaf.rounds_options'),
            'questionSecondsOptions' => config('hadaf.question_seconds_options'),
        ]);
    }

    private function authorizeChannelMember(Request $request, Game $game): void
    {
        abort_unless($game->game_type === HadafEngine::TYPE, 404);

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
