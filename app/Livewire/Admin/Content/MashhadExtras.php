<?php

namespace App\Livewire\Admin\Content;

use App\Livewire\Admin\AdminComponent;
use App\Livewire\Admin\Concerns\EditsSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * أدوار الحشو وجوائز السهرة ومجموعات لعبة المشهد.
 */
#[Layout('admin.layout')]
#[Title('أدوار وجوائز المشهد')]
class MashhadExtras extends AdminComponent
{
    use EditsSettings;

    /** @var array<int, array{name: string, goal: string, difficulty: string}> */
    public array $fillerRoles = [];

    /** @var array<int, array{key: string, emoji: string, label: string}> */
    public array $awards = [];

    /** @var array<string, array{label: string, emoji: string}> */
    public array $categories = [];

    public function mount(): void
    {
        $this->loadValues();
    }

    private function loadValues(): void
    {
        $this->fillerRoles = array_values(config('mashhad.filler_roles', []));
        $this->awards = array_values(config('mashhad.awards', []));
        $this->categories = config('mashhad.categories', []);
    }

    /**
     * أقل عدد أدوار حشو: أكبر مجموعة لاعبين ناقص أقل عدد أدوار أساسية
     * يقبله المشهد — فلا تبقى غرفة ممتلئة بلا أدوار لبعض لاعبيها.
     */
    private function minFillers(): int
    {
        return max(1, (int) config('mashhad.limits.max_players') - (int) config('mashhad.limits.min_players'));
    }

    public function addRole(): void
    {
        $this->fillerRoles[] = ['name' => '', 'goal' => '', 'difficulty' => 'easy'];
    }

    public function removeRole(int $index): void
    {
        unset($this->fillerRoles[$index]);
        $this->fillerRoles = array_values($this->fillerRoles);
    }

    public function addAward(): void
    {
        $this->awards[] = ['key' => '', 'emoji' => '🏆', 'label' => ''];
    }

    public function removeAward(int $index): void
    {
        unset($this->awards[$index]);
        $this->awards = array_values($this->awards);
    }

    public function saveRoles(): void
    {
        $this->validate([
            'fillerRoles' => ['required', 'array', 'min:'.$this->minFillers(), 'max:200'],
            'fillerRoles.*.name' => ['required', 'string', 'max:40'],
            'fillerRoles.*.goal' => ['required', 'string', 'max:200'],
            'fillerRoles.*.difficulty' => ['required', 'in:easy,medium,hard'],
        ], [
            'fillerRoles.min' => "لازم {$this->minFillers()} أدوار على الأقل حتى تكفي الغرفة الكاملة.",
        ], [
            'fillerRoles' => 'أدوار الحشو',
            'fillerRoles.*.name' => 'اسم الدور',
            'fillerRoles.*.goal' => 'الهدف',
            'fillerRoles.*.difficulty' => 'الصعوبة',
        ]);

        // الوضع العائلي يسحب أدواراً سهلة فقط.
        if (collect($this->fillerRoles)->where('difficulty', 'easy')->count() < 3) {
            $this->addError('fillerRoles', 'لازم 3 أدوار سهلة على الأقل — الوضع العائلي بيعتمد عليها.');

            return;
        }

        $value = array_map(fn (array $role) => [
            'name' => trim($role['name']),
            'goal' => trim($role['goal']),
            'difficulty' => $role['difficulty'],
        ], $this->fillerRoles);

        $this->done($this->saveSetting('mashhad.filler_roles', $value, 'أدوار الحشو في المشهد'));
    }

    public function saveAwards(): void
    {
        $this->validate([
            'awards' => ['required', 'array', 'min:1', 'max:8'],
            'awards.*.key' => ['required', 'regex:/^[a-z0-9_]{2,30}$/', 'distinct'],
            'awards.*.emoji' => ['required', 'string', 'max:8'],
            'awards.*.label' => ['required', 'string', 'max:40'],
        ], [], [
            'awards' => 'الجوائز',
            'awards.*.key' => 'المفتاح',
            'awards.*.emoji' => 'الإيموجي',
            'awards.*.label' => 'اسم الجائزة',
        ]);

        $value = array_map(fn (array $award) => [
            'key' => $award['key'],
            'emoji' => trim($award['emoji']),
            'label' => trim($award['label']),
        ], $this->awards);

        $this->done($this->saveSetting('mashhad.awards', $value, 'جوائز سهرة المشهد'));
    }

    public function saveCategories(): void
    {
        $value = [];

        foreach (array_keys($this->settings()->default('mashhad.categories') ?? []) as $key) {
            $label = trim((string) ($this->categories[$key]['label'] ?? ''));

            if ($label === '' || mb_strlen($label) > 30) {
                $this->addError("categories.{$key}.label", 'الاسم مطلوب وأقصاه 30 حرف.');

                return;
            }

            $value[$key] = ['label' => $label, 'emoji' => trim((string) ($this->categories[$key]['emoji'] ?? ''))];
        }

        $this->done($this->saveSetting('mashhad.categories', $value, 'مجموعات مشاهد المشهد'));
    }

    public function resetSection(string $key): void
    {
        $labels = [
            'mashhad.filler_roles' => 'أدوار الحشو في المشهد',
            'mashhad.awards' => 'جوائز سهرة المشهد',
            'mashhad.categories' => 'مجموعات مشاهد المشهد',
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
        $this->notify($changed ? 'انحفظ — بيسري من المشهد الجاي.' : 'ما في تغيير.', $changed ? 'success' : 'warning');
    }

    public function render()
    {
        $store = $this->settings();

        return view('livewire.admin.content.mashhad-extras', [
            'overridden' => collect(['mashhad.filler_roles', 'mashhad.awards', 'mashhad.categories'])
                ->mapWithKeys(fn ($key) => [$key => $store->isOverridden($key)])
                ->all(),
            'levels' => HarfContent::LEVELS,
            'minFillers' => $this->minFillers(),
        ]);
    }
}
