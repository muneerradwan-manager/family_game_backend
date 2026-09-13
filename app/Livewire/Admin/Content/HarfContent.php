<?php

namespace App\Livewire\Admin\Content;

use App\Livewire\Admin\AdminComponent;
use App\Livewire\Admin\Concerns\EditsSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * محتوى لعبة الحروف: بنك الحروف، أسماء الأعمدة، والعمود السادس.
 *
 * الأعمدة الخمسة الأساسية تُعاد تسميتها ولا تُضاف أو تُحذف: التطبيق يرسم
 * ورقة الكتابة على خمس خانات، ومفاتيحها محفوظة في أرشيف الجولات القديمة.
 */
#[Layout('admin.layout')]
#[Title('محتوى لعبة الحروف')]
class HarfContent extends AdminComponent
{
    use EditsSettings;

    public string $letters = '';

    /** @var array<string, string> */
    public array $columns = [];

    /** @var array<int, string> */
    public array $flexibleColumns = [];

    /** @var array<int, array{key: string, label: string, level: string}> */
    public array $sixthColumns = [];

    public const LEVELS = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

    public function mount(): void
    {
        $this->loadValues();
    }

    private function loadValues(): void
    {
        $this->letters = implode(' ', config('harf.letters', []));
        $this->columns = config('harf.columns', []);
        $this->flexibleColumns = array_values(config('harf.flexible_columns', []));
        $this->sixthColumns = array_values(config('harf.sixth_columns', []));
    }

    public function saveLetters(): void
    {
        $letters = array_values(array_unique(preg_split('/[\s,،]+/u', trim($this->letters), -1, PREG_SPLIT_NO_EMPTY) ?: []));

        foreach ($letters as $letter) {
            if (mb_strlen($letter) !== 1) {
                $this->addError('letters', "«{$letter}» مش حرف واحد.");

                return;
            }
        }

        if (count($letters) < 10) {
            $this->addError('letters', 'لازم 10 حروف على الأقل حتى ما تتكرر الحروف بسرعة.');

            return;
        }

        $this->done($this->saveSetting('harf.letters', $letters, 'بنك حروف لعبة الحروف'));
        $this->letters = implode(' ', $letters);
    }

    public function saveColumns(): void
    {
        $labels = [];

        foreach (array_keys($this->settings()->default('harf.columns') ?? []) as $key) {
            $label = trim((string) ($this->columns[$key] ?? ''));

            if ($label === '' || mb_strlen($label) > 30) {
                $this->addError("columns.{$key}", 'الاسم مطلوب وأقصاه 30 حرف.');

                return;
            }

            $labels[$key] = $label;
        }

        $this->done($this->saveSetting('harf.columns', $labels, 'أسماء أعمدة لعبة الحروف'));
    }

    public function saveFlexible(): void
    {
        $valid = array_keys(config('harf.columns', []));
        $chosen = array_values(array_filter($valid, fn ($key) => in_array($key, $this->flexibleColumns, true)));

        if (count($chosen) < 2) {
            $this->addError('flexibleColumns', 'اختار عمودين على الأقل.');

            return;
        }

        $this->done($this->saveSetting('harf.flexible_columns', $chosen, 'أعمدة الوضع المرن'));
        $this->flexibleColumns = $chosen;
    }

    public function addSixth(): void
    {
        $this->sixthColumns[] = ['key' => '', 'label' => '', 'level' => 'easy'];
    }

    public function removeSixth(int $index): void
    {
        unset($this->sixthColumns[$index]);
        $this->sixthColumns = array_values($this->sixthColumns);
    }

    public function saveSixth(): void
    {
        $this->validate([
            'sixthColumns' => ['required', 'array', 'min:1', 'max:60'],
            'sixthColumns.*.key' => ['required', 'regex:/^[a-z0-9_]{2,30}$/', 'distinct'],
            'sixthColumns.*.label' => ['required', 'string', 'max:30'],
            'sixthColumns.*.level' => ['required', 'in:easy,medium,hard'],
        ], [], [
            'sixthColumns' => 'العمود السادس',
            'sixthColumns.*.key' => 'المفتاح',
            'sixthColumns.*.label' => 'الاسم',
            'sixthColumns.*.level' => 'المستوى',
        ]);

        // أسماء الأعمدة الأساسية محجوزة: عمود سادس بمفتاح «name» يخلط الإجابات.
        foreach ($this->sixthColumns as $index => $column) {
            if (array_key_exists($column['key'], config('harf.columns', []))) {
                $this->addError("sixthColumns.{$index}.key", 'هذا مفتاح عمود أساسي.');

                return;
            }
        }

        $value = array_map(fn (array $column) => [
            'key' => $column['key'],
            'label' => trim($column['label']),
            'level' => $column['level'],
        ], $this->sixthColumns);

        $this->done($this->saveSetting('harf.sixth_columns', $value, 'خيارات العمود السادس'));
    }

    public function resetSection(string $key): void
    {
        $labels = [
            'harf.letters' => 'بنك حروف لعبة الحروف',
            'harf.columns' => 'أسماء أعمدة لعبة الحروف',
            'harf.flexible_columns' => 'أعمدة الوضع المرن',
            'harf.sixth_columns' => 'خيارات العمود السادس',
        ];

        if (! isset($labels[$key])) {
            return;
        }

        $this->resetSetting($key, $labels[$key]);
        $this->loadValues();
        $this->resetValidation();
        $this->notify('رجع للافتراضي.');
    }

    private function done(bool $changed): void
    {
        $this->resetValidation();
        $this->notify($changed ? 'انحفظ — بيسري من الجولة الجاية.' : 'ما في تغيير.', $changed ? 'success' : 'warning');
    }

    public function render()
    {
        $store = $this->settings();

        return view('livewire.admin.content.harf', [
            'overridden' => collect(['harf.letters', 'harf.columns', 'harf.flexible_columns', 'harf.sixth_columns'])
                ->mapWithKeys(fn ($key) => [$key => $store->isOverridden($key)])
                ->all(),
            'levels' => self::LEVELS,
        ]);
    }
}
