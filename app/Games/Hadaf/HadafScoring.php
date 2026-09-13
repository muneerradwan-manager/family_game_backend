<?php

namespace App\Games\Hadaf;

/**
 * نقاط جولة واحدة — السيرفر حصراً.
 *
 * ثلاث طبقات متعمّدة:
 *
 * 1. **الصحيح** يعطي نقاطاً ثابتة — فمن يعرف الجواب لا يخرج صفر اليدين مهما
 *    كان بطيئاً.
 * 2. **السرعة** تعطي مكافأة للأوائل الثلاثة من المصيبين — وهي ما يجعلها
 *    سباقاً لا امتحاناً.
 * 3. **السلسلة** تكافئ الاستمرار — وهي طريق العودة لمن تأخّر: ثلاث صحيحات
 *    متتالية تعيده للمنافسة ولو خسر أول جولتين.
 *
 * و⚡ يضاعف الحصيلة كلها أو يخصم — وهو القرار الوحيد الذي يملكه اللاعب.
 */
class HadafScoring
{
    /**
     * @param  array<string, array{choice: int|null, correct: bool, at: int|null, risk: bool}>  $answers
     * @param  array<int, string>  $playerIds
     * @param  array<string, int>  $streaks  السلسلة بعد احتساب هذه الجولة
     * @return array<string, array<string, int|bool>>
     */
    public function score(array $answers, array $playerIds, array $streaks): array
    {
        $points = config('hadaf.points');
        $rows = [];

        // ترتيب المصيبين بختم السيرفر: من وصل أولاً فعلاً، لا من ادّعى.
        $correct = array_filter(
            $answers,
            fn (array $answer, string $userId) => $answer['correct'] && in_array($userId, $playerIds, true),
            ARRAY_FILTER_USE_BOTH,
        );

        uasort($correct, fn (array $a, array $b) => $a['at'] <=> $b['at']);
        $ranking = array_keys($correct);

        foreach ($playerIds as $userId) {
            $answer = $answers[$userId] ?? null;
            $answered = $answer !== null && $answer['choice'] !== null;
            $isCorrect = $answered && $answer['correct'];
            $risk = $answered && $answer['risk'];

            $base = 0;
            $speed = 0;
            $streakBonus = 0;
            $riskDelta = 0;

            if ($isCorrect) {
                $base = (int) $points['correct'];

                $rank = array_search($userId, $ranking, true);
                $speed = (int) ($points['speed_bonus'][$rank] ?? 0);

                if (($streaks[$userId] ?? 0) >= (int) $points['streak_threshold']) {
                    $streakBonus = (int) $points['streak_bonus'];
                }
            } elseif ($answered) {
                $base = (int) $points['wrong'];
            } else {
                $base = (int) $points['no_answer'];
            }

            $subtotal = $base + $speed + $streakBonus;

            if ($risk) {
                // المضاعفة على الحصيلة كلها: المخاطرة تستحق أن تُحسّ.
                $riskDelta = $isCorrect
                    ? $subtotal * ((int) $points['risk_multiplier'] - 1)
                    : (int) $points['risk_penalty'];
            }

            $rows[$userId] = [
                'base' => $base,
                'speed' => $speed,
                'streak' => $streakBonus,
                'risk' => $riskDelta,
                'total' => $subtotal + $riskDelta,
                'correct' => $isCorrect,
                'answered' => $answered,
                'usedRisk' => $risk,
                'rank' => $isCorrect ? (int) array_search($userId, $ranking, true) + 1 : 0,
            ];
        }

        return $rows;
    }
}
