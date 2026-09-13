<?php

namespace App\Livewire\Admin\Content;

use App\Livewire\Admin\AdminComponent;
use App\Livewire\Admin\Concerns\EditsSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * مجموعات كلمات لعبة الجاسوس.
 */
#[Layout('admin.layout')]
#[Title('كلمات الجاسوس')]
class SpyWords extends AdminComponent
{
    use EditsSettings;

    private const KEY = 'spy.categories';

    /** @var array<int, array{key: string, label: string, emoji: string, words: string}> */
    public array $categories = [];

    public function mount(): void
    {
        $this->loadValues();
    }

    private function loadValues(): void
    {
        $this->categories = collect(config(self::KEY, []))
            ->map(fn (array $category, string $key) => [
                'key' => $key,
                'label' => $category['label'] ?? $key,
                'emoji' => $category['emoji'] ?? '',
                'words' => implode("\n", $category['words'] ?? []),
            ])
            ->values()
            ->all();
    }

    /** أقل عدد كلمات: فرصة الجاسوس الأخيرة تسحب خياراتها كلها من المجموعة نفسها. */
    private function minWords(): int
    {
        return max(6, (int) config('spy.limits.guess_options'));
    }

    public function add(): void
    {
        $this->categories[] = ['key' => '', 'label' => '', 'emoji' => '🃏', 'words' => ''];
    }

    public function remove(int $index): void
    {
        unset($this->categories[$index]);
        $this->categories = array_values($this->categories);
    }

    public function save(): void
    {
        $this->validate([
            'categories' => ['required', 'array', 'min:1', 'max:100'],
            'categories.*.key' => ['required', 'regex:/^[a-z0-9_]{2,30}$/', 'distinct'],
            'categories.*.label' => ['required', 'string', 'max:30'],
            'categories.*.emoji' => ['nullable', 'string', 'max:8'],
            'categories.*.words' => ['required', 'string'],
        ], [], [
            'categories' => 'المجموعات',
            'categories.*.key' => 'المفتاح',
            'categories.*.label' => 'الاسم',
            'categories.*.emoji' => 'الإيموجي',
            'categories.*.words' => 'الكلمات',
        ]);

        $value = [];

        foreach ($this->categories as $index => $category) {
            $words = $this->parseWords($category['words']);

            if (count($words) < $this->minWords()) {
                $this->addError("categories.{$index}.words", "لازم {$this->minWords()} كلمات مختلفة على الأقل (فيها ".count($words).').');

                return;
            }

            foreach ($words as $word) {
                if (mb_strlen($word) > 40) {
                    $this->addError("categories.{$index}.words", "«{$word}» طويلة — أقصى 40 حرف.");

                    return;
                }
            }

            $value[$category['key']] = [
                'label' => trim($category['label']),
                'emoji' => trim((string) $category['emoji']),
                'words' => $words,
            ];
        }

        $changed = $this->saveSetting(self::KEY, $value, 'مجموعات كلمات الجاسوس');

        // مجموعة افتراضية في شاشة الشروط حُذفت: نعيدها لـ«مفاجأة».
        $default = config('spy.defaults.category');
        if ($default !== null && ! array_key_exists($default, $value)) {
            $this->saveSetting('spy.defaults.category', null, 'المجموعة الافتراضية للجاسوس');
        }

        $this->resetValidation();
        $this->loadValues();
        $this->notify($changed ? 'انحفظت الكلمات — بتسري من الجلسة الجاية.' : 'ما في تغيير.', $changed ? 'success' : 'warning');
    }

    public function resetSection(string $key): void
    {
        if ($key !== self::KEY) {
            return;
        }

        $this->resetSetting(self::KEY, 'مجموعات كلمات الجاسوس');
        $this->loadValues();
        $this->resetValidation();
        $this->notify('رجعت الكلمات للافتراضي.');
    }

    /** @return array<int, string> */
    private function parseWords(string $raw): array
    {
        $words = preg_split('/[\r\n,،]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (string $word) => trim(preg_replace('/\s+/u', ' ', $word)),
            $words,
        ), fn (string $word) => $word !== '')));
    }

    public function render()
    {
        return view('livewire.admin.content.spy', [
            'overridden' => $this->settings()->isOverridden(self::KEY),
            'minWords' => $this->minWords(),
        ]);
    }
}
