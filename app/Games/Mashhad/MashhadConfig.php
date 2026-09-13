<?php

namespace App\Games\Mashhad;

/**
 * شروط لعبة المشهد كما يحدّدها المنشئ، مُطبَّعة ومقفلة لحظة "ابدأ".
 */
final class MashhadConfig
{
    public function __construct(
        /** مفتاح المجموعة، أو null = مزيج. */
        public readonly ?string $category,
        public readonly int $scenes,
        public readonly int $sceneSeconds,
        public readonly bool $familyMode,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $defaults = config('mashhad.defaults');

        $family = (bool) ($input['familyMode'] ?? $defaults['family_mode']);

        $category = $input['category'] ?? $defaults['category'];
        $category = is_string($category) && $category !== '' ? $category : null;

        if ($category !== null && ! isset(config('mashhad.categories')[$category])) {
            $category = null;
        }

        $scenes = (int) ($input['scenes'] ?? $defaults['scenes']);
        if (! in_array($scenes, config('mashhad.scenes_options'), true)) {
            $scenes = (int) $defaults['scenes'];
        }

        $seconds = (int) ($input['sceneSeconds'] ?? $defaults['scene_seconds']);
        if (! in_array($seconds, config('mashhad.scene_seconds_options'), true)) {
            $seconds = (int) $defaults['scene_seconds'];
        }

        // الوضع العائلي يفرض وقتاً أطول — وبساطة الأدوار تُفرض عند التوزيع.
        if ($family) {
            $seconds = (int) config('mashhad.family.scene_seconds');
        }

        return new self($category, $scenes, $seconds, $family);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'categoryLabel' => $this->category === null
                ? null
                : config("mashhad.categories.{$this->category}.label"),
            'categoryEmoji' => $this->category === null
                ? null
                : config("mashhad.categories.{$this->category}.emoji"),
            'scenes' => $this->scenes,
            'sceneSeconds' => $this->sceneSeconds,
            'familyMode' => $this->familyMode,
            'challengesPerPlayer' => (int) config('mashhad.limits.challenges_per_player'),
        ];
    }
}
