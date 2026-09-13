<?php

namespace App\Http\Controllers\Api;

use App\Games\Spy\SpyEngine;
use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * نوايا لعبة الجاسوس.
 *
 * تصل عبر REST لا عبر رسائل WebSocket صاعدة — كما في بقية الألعاب: كل نيّة
 * تمر بنفس طبقة المصادقة والتحقق وحدّ الطلبات، والقناة الحيّة تبقى ذات اتجاه
 * واحد.
 *
 * والردّ هنا إقرار استلام (202) لا نتيجة. في هذه اللعبة تحديداً للأمر وجه
 * ثانٍ: لو حمل الردّ نتيجة النيّة لاستطاع لاعب أن يستنتج منها ما لا يحقّ له —
 * أن تصويته أغلق الجولة مثلاً. النتيجة تصل للجميع معاً عبر القناة.
 */
class SpyController extends Controller
{
    public function __construct(private readonly SpyEngine $engine) {}

    /** صاحب الدور يختار لاعباً ويكتب سؤاله. */
    public function ask(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'targetUserId' => ['required', 'uuid'],
            'question' => ['required', 'string', 'max:200'],
        ]);

        $this->engine->askQuestion($game, $request->user(), $data['targetUserId'], $data['question']);

        return $this->accepted();
    }

    /** المسؤول يجيب — بما يثبت أنه يعرف الكلمة دون أن يهديها للجاسوس. */
    public function answer(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'answer' => ['required', 'string', 'max:200'],
        ]);

        $this->engine->answerQuestion($game, $request->user(), $data['answer']);

        return $this->accepted();
    }

    public function vote(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'suspectUserId' => ['required', 'uuid'],
        ]);

        $this->engine->castVote($game, $request->user(), $data['suspectUserId']);

        return $this->accepted();
    }

    /** فرصة الجاسوس الأخيرة: اختيار من كلمات مجموعته. */
    public function guess(Request $request, Game $game): JsonResponse
    {
        $this->authorizeChannelMember($request, $game);

        $data = $request->validate([
            'word' => ['required', 'string', 'max:60'],
        ]);

        $this->engine->submitGuess($game, $request->user(), $data['word']);

        return $this->accepted();
    }

    /**
     * مجموعات الكلمات والمدد والسقوف — يقرأها التطبيق مرة ويخزّنها.
     *
     * الكلمات نفسها ليست هنا: لا داعي أن يحمل أي جهاز بنك الكلمات، ومن
     * يحمله يستطيع تضييق التخمين. التطبيق يحتاج الأسماء والأيقونات فقط.
     */
    public function reference(): JsonResponse
    {
        $categories = [];

        foreach (config('spy.categories') as $key => $category) {
            $categories[] = [
                'key' => $key,
                'label' => $category['label'],
                'emoji' => $category['emoji'],
                'wordCount' => count($category['words']),
            ];
        }

        return response()->json([
            'categories' => $categories,
            'phases' => config('spy.phases'),
            'limits' => config('spy.limits'),
            'turnSecondsOptions' => config('spy.turn_seconds_options'),
            'maxRoundsOptions' => config('spy.max_rounds_options'),
        ]);
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['accepted' => true], 202);
    }

    private function authorizeChannelMember(Request $request, Game $game): void
    {
        abort_unless($game->game_type === SpyEngine::TYPE, 404);

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
