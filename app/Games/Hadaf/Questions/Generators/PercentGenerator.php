<?php

namespace App\Games\Hadaf\Questions\Generators;

use App\Games\Hadaf\Questions\Question;
use App\Games\Hadaf\Questions\QuestionGenerator;

/**
 * نِسَب مئوية وكسور بسيطة — حساب الحياة اليومية.
 *
 * الأرقام مختارة لتُحسب ذهنياً: نسب من مضاعفات العشرة، وكسور من أعداد تقبل
 * القسمة. سؤال يحتاج ورقة وقلم لا محل له في لعبة مدّتها عشرون ثانية.
 */
class PercentGenerator implements QuestionGenerator
{
    public function category(): string
    {
        return 'math';
    }

    public function supports(string $difficulty): bool
    {
        return $difficulty !== 'easy';
    }

    public function generate(string $difficulty): Question
    {
        return match (random_int(0, 2)) {
            0 => $this->percentOf($difficulty),
            1 => $this->fractionOf(),
            default => $this->discount(),
        };
    }

    private function percentOf(string $difficulty): Question
    {
        $percent = $difficulty === 'hard'
            ? [15, 25, 35, 40, 60, 75][random_int(0, 5)]
            : [10, 20, 25, 50][random_int(0, 3)];

        $base = random_int(2, 20) * 20;
        $answer = (int) round($base * $percent / 100);

        return Question::make(
            'math',
            $difficulty,
            "كم يساوي {$percent}% من {$base}؟",
            (string) $answer,
            [
                (string) ($base - $answer),          // حسب الباقي بدل النسبة
                (string) (int) round($answer / 2),
                (string) ($answer * 2),
                (string) ($answer + 10),
            ],
        );
    }

    private function fractionOf(): Question
    {
        [$numerator, $denominator] = [[1, 2], [1, 3], [1, 4], [2, 3], [3, 4], [2, 5]][random_int(0, 5)];

        $base = $denominator * random_int(3, 20);
        $answer = (int) ($base * $numerator / $denominator);

        return Question::make(
            'math',
            'medium',
            "كم يساوي {$numerator}/{$denominator} من {$base}؟",
            (string) $answer,
            [
                (string) ($base - $answer),
                (string) (int) ($base / $denominator),
                (string) ($answer + $numerator),
                (string) ($answer * 2),
            ],
        );
    }

    private function discount(): Question
    {
        $percent = [10, 20, 25, 50][random_int(0, 3)];
        $price = random_int(2, 20) * 20;
        $answer = (int) round($price * (100 - $percent) / 100);

        return Question::make(
            'math',
            'hard',
            "سعرها {$price} وعليها خصم {$percent}% — كم صار سعرها؟",
            (string) $answer,
            [
                // الخطأ الأشهر: يحسب قيمة الخصم ويقدّمها كسعر. (عند خصم 50%
                // يساوي الجواب فيُحذف تلقائياً — ولهذا بعده بدائل كافية.)
                (string) ($price - $answer),
                (string) $price,
                (string) ($answer - 10),
                (string) ($answer + 10),
                (string) ($answer + 20),
            ],
        );
    }
}
