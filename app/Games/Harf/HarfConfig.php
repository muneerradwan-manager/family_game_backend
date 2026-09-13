<?php

namespace App\Games\Harf;

/**
 * شروط اللعبة كما يحدّدها المنشئ، مُطبَّعة ومقفلة لحظة "ابدأ".
 */
final class HarfConfig
{
    public function __construct(
        public readonly ?string $sixthColumn,
        public readonly int $roundsPerPlayer,
        public readonly int $writeSeconds,
        public readonly bool $flexibleMode,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $defaults = config('harf.defaults');

        $flexible = (bool) ($input['flexibleMode'] ?? $defaults['flexible_mode']);

        $sixth = $input['sixthColumn'] ?? $defaults['sixth_column'];
        $sixth = is_string($sixth) && $sixth !== '' ? $sixth : null;

        if ($sixth !== null && ! in_array($sixth, self::sixthColumnKeys(), true)) {
            $sixth = null;
        }

        $rounds = (int) ($input['roundsPerPlayer'] ?? $defaults['rounds_per_player']);
        if (! in_array($rounds, config('harf.rounds_per_player_options'), true)) {
            $rounds = (int) $defaults['rounds_per_player'];
        }

        $write = (int) ($input['writeSeconds'] ?? $defaults['write_seconds']);
        if (! in_array($write, config('harf.write_seconds_options'), true)) {
            $write = (int) $defaults['write_seconds'];
        }

        // الوضع المرن يفرض 3 أعمدة ووقتاً أطول ويلغي العمود السادس.
        if ($flexible) {
            $sixth = null;
            $write = (int) config('harf.flexible_write_seconds');
        }

        return new self($sixth, $rounds, $write, $flexible);
    }

    /**
     * مفاتيح الأعمدة بترتيب العرض.
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        if ($this->flexibleMode) {
            return config('harf.flexible_columns');
        }

        $columns = array_keys(config('harf.columns'));

        if ($this->sixthColumn !== null) {
            $columns[] = $this->sixthColumn;
        }

        return $columns;
    }

    /**
     * عناوين الأعمدة للعرض: مفتاح ← تسمية عربية.
     *
     * @return array<string, string>
     */
    public function columnLabels(): array
    {
        $fixed = config('harf.columns');
        $labels = [];

        foreach ($this->columns() as $key) {
            $labels[$key] = $fixed[$key] ?? self::sixthColumnLabel($key) ?? $key;
        }

        return $labels;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sixthColumn' => $this->sixthColumn,
            'roundsPerPlayer' => $this->roundsPerPlayer,
            'writeSeconds' => $this->writeSeconds,
            'flexibleMode' => $this->flexibleMode,
            'columns' => $this->columns(),
            'columnLabels' => $this->columnLabels(),
        ];
    }

    /** @return array<int, string> */
    public static function sixthColumnKeys(): array
    {
        return array_column(config('harf.sixth_columns'), 'key');
    }

    public static function sixthColumnLabel(string $key): ?string
    {
        foreach (config('harf.sixth_columns') as $option) {
            if ($option['key'] === $key) {
                return $option['label'];
            }
        }

        return null;
    }
}
