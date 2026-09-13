<?php

namespace App\Livewire\Admin\Games;

use App\Admin\GameSettingsDefinitions;
use App\Games\GameModuleRegistry;
use App\Livewire\Admin\AdminComponent;
use App\Livewire\Admin\Concerns\EditsSettings;
use Livewire\Attributes\Layout;

/**
 * محرّر أرقام لعبة واحدة: مدد المراحل، النقاط، السقوف، وخيارات الشروط.
 *
 * التعديل يسري فوراً — حتى على الجلسات الجارية من مرحلتها التالية، لأن
 * المحرّكات تقرأ config عند كل مؤقّت. هذا مقصود: مشرف لاحظ مرحلة قصيرة
 * يصلحها الآن لا بعد أن تنتهي كل السهرات.
 */
#[Layout('admin.layout')]
class Settings extends AdminComponent
{
    use EditsSettings;

    public string $type = '';

    /**
     * القيم كما في النموذج — مفاتيحها مسار config بشرطتين بدل النقطة، لأن
     * Livewire يقرأ النقطة في wire:model كتداخل مصفوفات.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * قيد بين حقلين: القيمة الافتراضية لازم تكون من خيارات الشاشة، والحد
     * الأدنى لا يتجاوز الأعلى. بدونها يقبل النموذج إعداداً يُسقط شاشة الشروط.
     */
    private const DEFAULT_IN_OPTIONS = [
        'harf.defaults.write_seconds' => 'harf.write_seconds_options',
        'harf.defaults.rounds_per_player' => 'harf.rounds_per_player_options',
        'spy.defaults.turn_seconds' => 'spy.turn_seconds_options',
        'spy.defaults.max_rounds' => 'spy.max_rounds_options',
        'hadaf.defaults.rounds' => 'hadaf.rounds_options',
        'hadaf.defaults.question_seconds' => 'hadaf.question_seconds_options',
        'mashhad.defaults.scenes' => 'mashhad.scenes_options',
        'mashhad.defaults.scene_seconds' => 'mashhad.scene_seconds_options',
    ];

    public function mount(string $type, GameModuleRegistry $registry): void
    {
        abort_unless($registry->has($type), 404);

        $this->type = $type;
        $this->loadValues();
    }

    public static function slot(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function loadValues(): void
    {
        $this->values = [];

        foreach (GameSettingsDefinitions::for($this->type) as $section) {
            foreach ($section['fields'] as $field) {
                $this->values[self::slot($field['key'])] = $this->toForm($field, config($field['key']));
            }
        }
    }

    public function save(): void
    {
        $this->resetValidation();

        $parsed = [];

        foreach (GameSettingsDefinitions::for($this->type) as $section) {
            foreach ($section['fields'] as $field) {
                $slot = self::slot($field['key']);
                $result = $this->parse($field, $this->values[$slot] ?? null);

                if (is_string($result)) {
                    $this->addError("values.{$slot}", $result);

                    continue;
                }

                $parsed[$field['key']] = $result[0];
            }
        }

        $this->crossCheck($parsed);

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->notify('في قيم غير صالحة — راجع الحقول المعلّمة.', 'error');

            return;
        }

        $changed = 0;

        foreach ($parsed as $key => $value) {
            $label = GameSettingsDefinitions::field($this->type, $key)['label'] ?? $key;

            if ($this->saveSetting($key, $value, "{$this->gameName()}: {$label}")) {
                $changed++;
            }
        }

        $this->loadValues();
        $this->notify($changed > 0 ? "انحفظ {$changed} تعديل — سارية فوراً." : 'ما في تغيير.', $changed > 0 ? 'success' : 'warning');
    }

    public function resetField(string $key): void
    {
        if (! in_array($key, GameSettingsDefinitions::keysFor($this->type), true)) {
            return;
        }

        $label = GameSettingsDefinitions::field($this->type, $key)['label'] ?? $key;
        $this->resetSetting($key, "{$this->gameName()}: {$label}");

        $field = GameSettingsDefinitions::field($this->type, $key);
        $this->values[self::slot($key)] = $this->toForm($field, config($key));
        $this->resetValidation('values.'.self::slot($key));
        $this->notify("رجع «{$label}» للافتراضي.");
    }

    public function resetAll(): void
    {
        $count = 0;

        foreach (GameSettingsDefinitions::keysFor($this->type) as $key) {
            $label = GameSettingsDefinitions::field($this->type, $key)['label'] ?? $key;

            if ($this->resetSetting($key, "{$this->gameName()}: {$label}")) {
                $count++;
            }
        }

        $this->loadValues();
        $this->resetValidation();
        $this->notify($count > 0 ? "رجع {$count} إعداد للافتراضي." : 'كل شي أصلاً على الافتراضي.');
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{0: mixed}|string القيمة داخل مصفوفة، أو رسالة الخطأ
     */
    private function parse(array $field, mixed $raw): array|string
    {
        switch ($field['type']) {
            case 'bool':
                return [(bool) $raw];

            case 'int':
                if (! is_numeric($raw) || (string) (int) $raw !== trim((string) $raw)) {
                    return 'لازم رقم صحيح.';
                }

                $value = (int) $raw;

                return $value < $field['min'] || $value > $field['max']
                    ? "بين {$field['min']} و {$field['max']}."
                    : [$value];

            case 'select':
                return array_key_exists((string) $raw, $field['options']) ? [(string) $raw] : 'اختيار غير صالح.';

            case 'int_list':
                $parts = preg_split('/[\s,،]+/u', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                if ($parts === []) {
                    return 'اكتب رقم واحد على الأقل.';
                }

                $values = [];

                foreach ($parts as $part) {
                    if (! preg_match('/^-?\d+$/', $part)) {
                        return "«{$part}» مش رقم صحيح.";
                    }

                    $number = (int) $part;

                    if ($number < $field['min'] || $number > $field['max']) {
                        return "كل رقم بين {$field['min']} و {$field['max']}.";
                    }

                    $values[] = $number;
                }

                return [$values];
        }

        return [$raw];
    }

    /** @param array<string, mixed> $parsed */
    private function crossCheck(array $parsed): void
    {
        foreach (self::DEFAULT_IN_OPTIONS as $defaultKey => $optionsKey) {
            if (! array_key_exists($defaultKey, $parsed) || ! array_key_exists($optionsKey, $parsed)) {
                continue;
            }

            if (! in_array($parsed[$defaultKey], $parsed[$optionsKey], true)) {
                $this->addError('values.'.self::slot($defaultKey), 'لازم تكون وحدة من الخيارات: '.implode('، ', $parsed[$optionsKey]));
            }
        }

        $min = "{$this->type}.limits.min_players";
        $max = "{$this->type}.limits.max_players";

        if (isset($parsed[$min], $parsed[$max]) && $parsed[$min] > $parsed[$max]) {
            $this->addError('values.'.self::slot($max), 'أقل من «أقل عدد لاعبين».');
        }

        if (isset($parsed['mashhad.events.count'])) {
            $count = $parsed['mashhad.events.count'];

            if (count($count) !== 2 || $count[0] > $count[1]) {
                $this->addError('values.'.self::slot('mashhad.events.count'), 'رقمين بالضبط: من، إلى (والأول أصغر).');
            }
        }

        if (isset($parsed['hadaf.choices']) && $parsed['hadaf.choices'] > 4) {
            // البنك المكتوب فيه ثلاث مضلِّلات لكل سؤال — أكثر من 4 خيارات يُسقط
            // الأسئلة القديمة من السحب.
            $this->addError('values.'.self::slot('hadaf.choices'), 'أسئلة البنك الحالية فيها 4 خيارات — زيادتها بتستبعدها.');
        }
    }

    /** @param array<string, mixed> $field */
    private function toForm(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'bool' => (bool) $value,
            'int_list' => implode(', ', (array) $value),
            default => $value === null ? '' : (string) $value,
        };
    }

    private function gameName(): string
    {
        $registry = app(GameModuleRegistry::class);

        return $registry->presentation($registry->get($this->type))['name'];
    }

    public function render()
    {
        $store = $this->settings();
        $sections = GameSettingsDefinitions::for($this->type);

        $overridden = [];
        $defaults = [];

        foreach ($sections as $section) {
            foreach ($section['fields'] as $field) {
                $overridden[$field['key']] = $store->isOverridden($field['key']);
                $defaults[$field['key']] = $this->toForm($field, $store->default($field['key']));
            }
        }

        return view('livewire.admin.games.settings', [
            'sections' => $sections,
            'overridden' => $overridden,
            'defaults' => $defaults,
            'gameName' => $this->gameName(),
        ])->title('إعدادات '.$this->gameName());
    }
}
