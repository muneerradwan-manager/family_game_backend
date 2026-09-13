<?php

namespace App\Games\Hadaf\Questions\Generators;

use App\Games\Hadaf\Questions\Question;
use App\Games\Hadaf\Questions\QuestionGenerator;

/**
 * حساب سريع: جمع وطرح وضرب وقسمة.
 *
 * المضلِّلات ليست أرقاماً عشوائية بل **أخطاء يقع فيها الحاسب المستعجل فعلاً**
 * — نتيجة العملية المعاكسة، أو انزلاق بمنزلة واحدة، أو خطأ حَمْل. مضلِّل
 * عشوائي بعيد يُستبعد بنظرة، فيتحوّل السؤال من أربعة خيارات إلى اثنين.
 */
class ArithmeticGenerator implements QuestionGenerator
{
    public function category(): string
    {
        return 'math';
    }

    public function supports(string $difficulty): bool
    {
        return true;
    }

    public function generate(string $difficulty): Question
    {
        return match ($difficulty) {
            'easy' => $this->twoTerms(2, 40, ['+', '-']),
            'medium' => random_int(0, 1) === 0
                ? $this->twoTerms(6, 99, ['+', '-', '×'])
                : $this->division(2, 12, 2, 12),
            default => random_int(0, 1) === 0
                ? $this->threeTerms()
                : $this->twoTerms(12, 25, ['×']),
        };
    }

    /** @param array<int, string> $operators */
    private function twoTerms(int $min, int $max, array $operators): Question
    {
        $operator = $operators[random_int(0, count($operators) - 1)];

        $a = random_int($min, $max);
        $b = random_int($min, $max);

        // الطرح بلا نتيجة سالبة: اللعبة عائلية والسالب يربك الصغار.
        if ($operator === '-' && $b > $a) {
            [$a, $b] = [$b, $a];
        }

        // الضرب يبقى في حدود الحساب الذهني.
        if ($operator === '×') {
            $a = random_int($min, min($max, 25));
            $b = random_int(2, 12);
        }

        $answer = match ($operator) {
            '+' => $a + $b,
            '-' => $a - $b,
            default => $a * $b,
        };

        return Question::make(
            'math',
            $this->difficultyOf($operator, $answer),
            "{$a} {$operator} {$b} = ؟",
            (string) $answer,
            $this->nearMisses($answer, $a, $b, $operator),
        );
    }

    private function division(int $minDivisor, int $maxDivisor, int $minQuotient, int $maxQuotient): Question
    {
        // نبني من الناتج لا من المقسوم: فتكون القسمة صحيحة دائماً بلا باقٍ.
        $divisor = random_int($minDivisor, $maxDivisor);
        $quotient = random_int($minQuotient, $maxQuotient);
        $dividend = $divisor * $quotient;

        return Question::make(
            'math',
            'medium',
            "{$dividend} ÷ {$divisor} = ؟",
            (string) $quotient,
            $this->nearMisses($quotient, $dividend, $divisor, '÷'),
        );
    }

    private function threeTerms(): Question
    {
        $a = random_int(2, 12);
        $b = random_int(2, 12);
        $c = random_int(2, 30);
        $plus = random_int(0, 1) === 0;

        $answer = $plus ? $a * $b + $c : $a * $b - $c;
        $sign = $plus ? '+' : '−';

        return Question::make(
            'math',
            'hard',
            "{$a} × {$b} {$sign} {$c} = ؟",
            (string) $answer,
            [
                // الفخّ الكلاسيكي: من يطبّق العمليات من اليسار بلا أولوية الضرب.
                (string) ($plus ? $a * ($b + $c) : $a * ($b - $c)),
                (string) ($plus ? $answer - $c * 2 : $answer + $c * 2),
                (string) ($answer + $a),
                (string) ($answer - $b),
            ],
        );
    }

    /**
     * أخطاء قريبة محتملة — لا أرقام عشوائية.
     *
     * @return array<int, string>
     */
    private function nearMisses(int $answer, int $a, int $b, string $operator): array
    {
        $candidates = match ($operator) {
            '+' => [$a - $b, $answer + 1, $answer - 1, $answer + 10, $answer - 10],
            '-' => [$a + $b, $answer + 1, $answer - 1, $answer + 10, $answer - 10],
            '×' => [$a * $b - $a, $a * $b + $a, $a + $b, $answer + 10, $answer - 10],
            default => [$a - $b, $answer + 1, $answer - 1, $answer * 2],
        };

        $candidates = array_values(array_unique(array_filter(
            $candidates,
            fn (int $value) => $value !== $answer && $value >= 0,
        )));

        shuffle($candidates);

        return array_map('strval', $candidates);
    }

    /** ضرب الأعداد الكبيرة أصعب من جمعها مهما كان الوضع المطلوب. */
    private function difficultyOf(string $operator, int $answer): string
    {
        if ($operator === '×' && $answer > 100) {
            return 'hard';
        }

        return $answer > 60 || $operator === '×' ? 'medium' : 'easy';
    }
}
