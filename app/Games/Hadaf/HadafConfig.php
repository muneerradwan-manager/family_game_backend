<?php

namespace App\Games\Hadaf;

/**
 * شروط لعبة الهدف كما يحدّدها المنشئ، مُطبَّعة ومقفلة لحظة "ابدأ".
 */
final class HadafConfig
{
    public function __construct(
        /** مفتاح المجموعة، أو null = مزيج من كل المجموعات. */
        public readonly ?string $category,
        public readonly int $rounds,
        public readonly int $questionSeconds,
        public readonly bool $flexibleMode,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $defaults = config('hadaf.defaults');

        $flexible = (bool) ($input['flexibleMode'] ?? $defaults['flexible_mode']);

        $category = $input['category'] ?? $defaults['category'];
        $category = is_string($category) && $category !== '' ? $category : null;

        if ($category !== null && ! isset(config('hadaf.categories')[$category])) {
            $category = null;
        }

        $rounds = (int) ($input['rounds'] ?? $defaults['rounds']);
        if (! in_array($rounds, config('hadaf.rounds_options'), true)) {
            $rounds = (int) $defaults['rounds'];
        }

        $seconds = (int) ($input['questionSeconds'] ?? $defaults['question_seconds']);
        if (! in_array($seconds, config('hadaf.question_seconds_options'), true)) {
            $seconds = (int) $defaults['question_seconds'];
        }

        // الوضع المرن يفرض وقتاً أطول — وصعوبته تُفرض عند سحب السؤال.
        if ($flexible) {
            $seconds = (int) config('hadaf.flexible.question_seconds');
        }

        return new self($category, $rounds, $seconds, $flexible);
    }

    /**
     * صعوبة سؤال الجولة رقم $round.
     *
     * تتدرّج مع تقدّم الجلسة: الجولات الأولى سهلة ليدخل الجميع، والأخيرة
     * صعبة ليُحسم الترتيب. والوضع المرن يبقى سهلاً حتى النهاية.
     */
    public function difficultyFor(int $round): string
    {
        if ($this->flexibleMode) {
            return (string) config('hadaf.flexible.difficulty');
        }

        $progress = $this->rounds < 2 ? 1.0 : ($round - 1) / ($this->rounds - 1);

        return match (true) {
            $progress < 0.34 => 'easy',
            $progress < 0.72 => 'medium',
            default => 'hard',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'categoryLabel' => $this->category === null
                ? null
                : config("hadaf.categories.{$this->category}.label"),
            'categoryEmoji' => $this->category === null
                ? null
                : config("hadaf.categories.{$this->category}.emoji"),
            'rounds' => $this->rounds,
            'questionSeconds' => $this->questionSeconds,
            'flexibleMode' => $this->flexibleMode,
            'risksPerGame' => (int) config('hadaf.limits.risks_per_game'),
        ];
    }
}
