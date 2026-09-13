<?php

namespace App\Games\Hadaf\Questions\Generators;

use App\Games\Hadaf\Questions\Question;
use App\Games\Hadaf\Questions\QuestionGenerator;

/**
 * متسلسلات: `2 · 4 · 8 · 16 · ؟`
 *
 * نعرض خمسة حدود لا ثلاثة: ثلاثة حدود تحتمل أكثر من قاعدة صحيحة (1·2·4 قد
 * تكون مضاعفة أو فروقاً متزايدة)، وسؤال له جوابان صحيحان يُغضب من يصيب
 * أحدهما ويُحتسب عليه خطأ.
 */
class SequenceGenerator implements QuestionGenerator
{
    private const TERMS = 5;

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
        $series = match ($difficulty) {
            'easy' => $this->arithmetic(random_int(1, 9), random_int(2, 9)),
            'medium' => random_int(0, 1) === 0
                ? $this->geometric(random_int(1, 4), random_int(2, 3))
                : $this->arithmetic(random_int(2, 20), random_int(-9, -2)),
            default => match (random_int(0, 2)) {
                0 => $this->fibonacci(),
                1 => $this->squares(),
                default => $this->growingStep(),
            },
        };

        $answer = array_pop($series);
        $shown = implode(' · ', $series).' · ؟';

        return Question::make(
            'math',
            $difficulty,
            $shown,
            (string) $answer,
            $this->nearMisses($series, $answer),
        );
    }

    /** @return array<int, int> */
    private function arithmetic(int $start, int $step): array
    {
        $values = [];

        for ($i = 0; $i <= self::TERMS; $i++) {
            $values[] = $start + $step * $i;
        }

        // متسلسلة تنازلية تعبر الصفر تُربك أكثر مما تتحدّى.
        return end($values) < 0 ? $this->arithmetic($start + 30, $step) : $values;
    }

    /** @return array<int, int> */
    private function geometric(int $start, int $ratio): array
    {
        $values = [];

        for ($i = 0; $i <= self::TERMS; $i++) {
            $values[] = $start * $ratio ** $i;
        }

        return $values;
    }

    /** @return array<int, int> */
    private function fibonacci(): array
    {
        $values = [random_int(1, 5), random_int(2, 8)];

        while (count($values) <= self::TERMS) {
            $values[] = $values[count($values) - 1] + $values[count($values) - 2];
        }

        return $values;
    }

    /** @return array<int, int> */
    private function squares(): array
    {
        $start = random_int(2, 6);
        $values = [];

        for ($i = 0; $i <= self::TERMS; $i++) {
            $values[] = ($start + $i) ** 2;
        }

        return $values;
    }

    /** فرق متزايد: 3 · 5 · 8 · 12 · 17 (الفرق +2 ثم +3 ثم +4...). */
    private function growingStep(): array
    {
        $value = random_int(1, 6);
        $step = random_int(2, 4);
        $values = [$value];

        for ($i = 0; $i < self::TERMS; $i++) {
            $value += $step;
            $step++;
            $values[] = $value;
        }

        return $values;
    }

    /**
     * مضلِّلات مبنية على أخطاء القاعدة لا على أرقام عشوائية: من يطبّق الفرق
     * الخطأ، أو يكرّر الحدّ الأخير، أو يخطئ بخطوة واحدة.
     *
     * @param  array<int, int>  $series
     * @return array<int, string>
     */
    private function nearMisses(array $series, int $answer): array
    {
        $last = (int) end($series);
        $previous = (int) $series[count($series) - 2];
        $lastStep = $last - $previous;

        $candidates = [
            $last + $lastStep,      // أكمل بالفرق الأخير بدل القاعدة
            $answer + $lastStep,
            $answer - $lastStep,
            $answer + 1,
            $answer - 1,
            $last * 2,
        ];

        $candidates = array_values(array_unique(array_filter(
            $candidates,
            fn (int $value) => $value !== $answer && $value > 0,
        )));

        shuffle($candidates);

        return array_map('strval', $candidates);
    }
}
