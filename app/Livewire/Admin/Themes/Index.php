<?php

namespace App\Livewire\Admin\Themes;

use App\Admin\AdminAudit;
use App\Livewire\Admin\AdminComponent;
use App\Models\AppTheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

#[Layout('admin.layout')]
#[Title('الثيمات')]
class Index extends AdminComponent
{
    /** null = النافذة مسكّرة، 0 = ثيم جديد، غير ذلك = تعديل. */
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function create(?int $copyFromId = null): void
    {
        $source = $copyFromId
            ? AppTheme::findOrFail($copyFromId)
            : (AppTheme::where('is_default', true)->first() ?? AppTheme::orderBy('sort_order')->first());

        $this->editingId = 0;
        $this->form = [
            'key' => $source ? $source->key.'_copy' : '',
            'name' => $source ? $source->name.' (نسخة)' : '',
            'tagline' => $source?->tagline ?? '',
            'is_dark' => $source?->is_dark ?? false,
            'is_active' => false,
            'sort_order' => (int) AppTheme::max('sort_order') + 1,
            'colors' => $this->completeColors($source?->colors ?? []),
        ];
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $theme = AppTheme::findOrFail($id);

        $this->editingId = $theme->id;
        $this->form = [
            'key' => $theme->key,
            'name' => $theme->name,
            'tagline' => (string) $theme->tagline,
            'is_dark' => $theme->is_dark,
            'is_active' => $theme->is_active,
            'sort_order' => $theme->sort_order,
            'colors' => $this->completeColors($theme->colors ?? []),
        ];
        $this->resetValidation();
    }

    public function save(): void
    {
        $theme = $this->editingId ? AppTheme::findOrFail($this->editingId) : new AppTheme;

        $rules = [
            'form.key' => ['required', 'regex:/^[a-z0-9_]{2,40}$/', Rule::unique('app_themes', 'key')->ignore($theme->id)],
            'form.name' => ['required', 'string', 'max:40'],
            'form.tagline' => ['nullable', 'string', 'max:80'],
            'form.is_dark' => ['boolean'],
            'form.is_active' => ['boolean'],
            'form.sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
        ];

        $attributes = [
            'form.key' => 'المفتاح',
            'form.name' => 'الاسم',
            'form.tagline' => 'الوصف',
            'form.sort_order' => 'الترتيب',
        ];

        foreach (AppTheme::COLOR_KEYS as $key => $label) {
            $rules["form.colors.{$key}"] = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];
            $attributes["form.colors.{$key}"] = $label;
        }

        $this->validate($rules, [], $attributes);

        // الثيم الافتراضي هو ما يفتح عليه جهاز جديد — لا يجوز أن يكون مخفياً.
        if ($theme->is_default && ! $this->form['is_active']) {
            $this->addError('form.is_active', 'الثيم الافتراضي لازم يضل مفعّل.');

            return;
        }

        $values = [
            'key' => $this->form['key'],
            'name' => trim($this->form['name']),
            'tagline' => trim((string) $this->form['tagline']) ?: null,
            'is_dark' => (bool) $this->form['is_dark'],
            'is_active' => (bool) $this->form['is_active'],
            'sort_order' => (int) $this->form['sort_order'],
            'colors' => collect(AppTheme::COLOR_KEYS)
                ->keys()
                ->mapWithKeys(fn ($key) => [$key => strtoupper($this->form['colors'][$key])])
                ->all(),
        ];

        $isNew = ! $theme->exists;
        $before = $theme->exists ? $theme->only(array_keys($values)) : [];

        $theme->fill($values)->save();

        AdminAudit::record(
            $isNew ? 'theme.created' : 'theme.updated',
            ($isNew ? 'أضاف الثيم' : 'عدّل الثيم')." «{$theme->name}»",
            $theme,
            AdminAudit::diff($before, $values),
        );

        $this->editingId = null;
        $this->notify('انحفظ الثيم — بيوصل للأجهزة مع أول فتح للتطبيق.');
    }

    public function toggleActive(int $id): void
    {
        $theme = AppTheme::findOrFail($id);

        if ($theme->is_default && $theme->is_active) {
            $this->notify('الثيم الافتراضي لازم يضل مفعّل — اختار افتراضي غيره أول.', 'error');

            return;
        }

        $theme->forceFill(['is_active' => ! $theme->is_active])->save();

        AdminAudit::record(
            $theme->is_active ? 'theme.activated' : 'theme.deactivated',
            ($theme->is_active ? 'فعّل الثيم' : 'أخفى الثيم')." «{$theme->name}»",
            $theme,
        );

        $this->notify($theme->is_active ? 'الثيم ظاهر بالتطبيق.' : 'الثيم مخفي من التطبيق.');
    }

    public function makeDefault(int $id): void
    {
        $theme = AppTheme::findOrFail($id);

        DB::transaction(function () use ($theme) {
            AppTheme::where('is_default', true)->update(['is_default' => false]);
            $theme->forceFill(['is_default' => true, 'is_active' => true])->save();
        });

        AdminAudit::record('theme.default', "جعل «{$theme->name}» الثيم الافتراضي", $theme);

        $this->notify("«{$theme->name}» صار الافتراضي.");
    }

    public function move(int $id, int $direction): void
    {
        $themes = AppTheme::orderBy('sort_order')->orderBy('id')->get()->values();
        $index = $themes->search(fn (AppTheme $theme) => $theme->id === $id);
        $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);

        if ($target === null || $target < 0 || $target >= $themes->count()) {
            return;
        }

        $ordered = $themes->all();
        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $theme) {
                $theme->forceFill(['sort_order' => $position])->save();
            }
        });
    }

    public function delete(int $id): void
    {
        $theme = AppTheme::findOrFail($id);

        if ($theme->is_default) {
            $this->notify('ما بتقدر تحذف الثيم الافتراضي.', 'error');

            return;
        }

        AdminAudit::record('theme.deleted', "حذف الثيم «{$theme->name}»", $theme, ['theme' => $theme->toApi()]);

        $theme->delete();

        $this->notify('انحذف الثيم. الأجهزة اللي كانت عليه بترجع للافتراضي.');
    }

    /**
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    private function completeColors(array $colors): array
    {
        $complete = [];

        foreach (array_keys(AppTheme::COLOR_KEYS) as $key) {
            $complete[$key] = $colors[$key] ?? '#000000';
        }

        return $complete;
    }

    public function render()
    {
        return view('livewire.admin.themes.index', [
            'themes' => AppTheme::orderBy('sort_order')->orderBy('id')->get(),
            'colorKeys' => AppTheme::COLOR_KEYS,
        ]);
    }
}
