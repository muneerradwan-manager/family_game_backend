<?php

namespace App\Games\Spy;

/**
 * شروط لعبة الجاسوس كما يحدّدها المنشئ، مُطبَّعة ومقفلة لحظة "ابدأ".
 */
final class SpyConfig
{
    public function __construct(
        /** مفتاح مجموعة الكلمات، أو null = مفاجأة (السيرفر يختار). */
        public readonly ?string $category,
        public readonly int $turnSeconds,
        public readonly int $maxRounds,
        public readonly bool $lastGuess,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $defaults = config('spy.defaults');

        $category = $input['category'] ?? $defaults['category'];
        $category = is_string($category) && $category !== '' ? $category : null;

        if ($category !== null && ! in_array($category, self::categoryKeys(), true)) {
            $category = null;
        }

        $turn = (int) ($input['turnSeconds'] ?? $defaults['turn_seconds']);
        if (! in_array($turn, config('spy.turn_seconds_options'), true)) {
            $turn = (int) $defaults['turn_seconds'];
        }

        $rounds = (int) ($input['maxRounds'] ?? $defaults['max_rounds']);
        if (! in_array($rounds, config('spy.max_rounds_options'), true)) {
            $rounds = (int) $defaults['max_rounds'];
        }

        return new self(
            $category,
            $turn,
            $rounds,
            (bool) ($input['lastGuess'] ?? $defaults['last_guess']),
        );
    }

    /**
     * ما يُبَث للجميع من الشروط.
     *
     * لاحظ ما ليس هنا: الكلمة. المجموعة معروفة للكل (وهي جزء من متعة اللعب
     * — الجاسوس يعرف أننا نتكلم عن حيوان)، أما الكلمة فتصل لكل لاعب على
     * حدة في لقطته وحده ولا تمرّ على القناة الحيّة أبداً.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'turnSeconds' => $this->turnSeconds,
            'maxRounds' => $this->maxRounds,
            'lastGuess' => $this->lastGuess,
        ];
    }

    /** @return array<int, string> */
    public static function categoryKeys(): array
    {
        return array_keys(config('spy.categories'));
    }

    public static function labelOf(string $key): ?string
    {
        return config("spy.categories.{$key}.label");
    }

    public static function emojiOf(string $key): ?string
    {
        return config("spy.categories.{$key}.emoji");
    }
}
