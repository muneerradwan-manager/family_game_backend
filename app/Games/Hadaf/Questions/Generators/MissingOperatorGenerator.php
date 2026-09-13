<?php

namespace App\Games\Hadaf\Questions\Generators;

use App\Games\Hadaf\Questions\Question;
use App\Games\Hadaf\Questions\QuestionGenerator;

/**
 * العملية الناقصة: `12 ؟ 4 = 48`
 *
 * تقلب اتجاه التفكير — بدل أن تحسب، تجرّب. وهي ألذّ سؤال في اللعبة لمن
 * يحسب بسرعة، لأن الخيارات الأربعة نفسها هي العمليات الأربع.
 */
class MissingOperatorGenerator implements QuestionGenerator
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
        $operators = $difficulty === 'easy' ? ['+', '-'] : ['+', '-', '×', '÷'];
        $operator = $operators[random_int(0, count($operators) - 1)];

        [$a, $b, $result] = $this->operands($operator, $difficulty);

        return Question::make(
            'math',
            $difficulty,
            "{$a} ؟ {$b} = {$result}",
            $operator,
            // الخيارات هي العمليات الأربع دائماً: لا مجال لتضليل مصطنع.
            array_values(array_diff(['+', '-', '×', '÷'], [$operator])),
            null,
        );
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function operands(string $operator, string $difficulty): array
    {
        $ceiling = $difficulty === 'hard' ? 40 : 20;

        return match ($operator) {
            '+' => (function () use ($ceiling) {
                $a = random_int(2, $ceiling);
                $b = random_int(2, $ceiling);

                return [$a, $b, $a + $b];
            })(),
            '-' => (function () use ($ceiling) {
                $a = random_int(10, $ceiling * 2);
                $b = random_int(2, 9);

                return [$a, $b, $a - $b];
            })(),
            '×' => (function () {
                $a = random_int(2, 12);
                $b = random_int(2, 12);

                return [$a, $b, $a * $b];
            })(),
            // نبني من الناتج فتكون القسمة صحيحة بلا باقٍ.
            default => (function () {
                $b = random_int(2, 12);
                $result = random_int(2, 12);

                return [$b * $result, $b, $result];
            })(),
        };
    }
}
