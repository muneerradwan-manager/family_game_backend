<?php

namespace App\Livewire\Admin\Content;

use App\Admin\AdminAudit;
use App\Livewire\Admin\AdminComponent;
use App\Livewire\Admin\Content\Concerns\ImportsBankFile;
use App\Models\MashhadScene;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * بنك مشاهد لعبة المشهد.
 */
#[Layout('admin.layout')]
#[Title('مشاهد المشهد')]
class MashhadScenes extends AdminComponent
{
    use ImportsBankFile;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    /** null = مسكّرة، 0 = جديد. */
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->editingId = 0;
        $this->form = [
            'key' => '',
            'category' => $this->category ?: array_key_first(config('mashhad.categories')),
            'title' => '',
            'setup' => '',
            'roles' => array_map(fn () => $this->blankRole(), range(1, max(3, (int) config('mashhad.limits.min_players')))),
            'events' => [['t' => '', 'scope' => 'all']],
        ];
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $scene = MashhadScene::findOrFail($id);

        $this->editingId = $scene->id;
        $this->form = [
            'key' => $scene->key,
            'category' => $scene->category,
            'title' => $scene->title,
            'setup' => $scene->setup,
            'roles' => array_map(fn (array $role) => [
                'n' => $role['n'] ?? '',
                'g' => $role['g'] ?? '',
                'b' => $role['b'] ?? '',
                's' => $role['s'] ?? '',
                'd' => $role['d'] ?? 'medium',
            ], $scene->roles ?? []),
            'events' => array_map(fn (array $event) => [
                't' => $event['t'] ?? '',
                'scope' => $event['scope'] ?? 'all',
            ], $scene->events ?? []),
        ];
        $this->resetValidation();
    }

    /** @return array{n: string, g: string, b: string, s: string, d: string} */
    private function blankRole(): array
    {
        return ['n' => '', 'g' => '', 'b' => '', 's' => '', 'd' => 'medium'];
    }

    public function addRole(): void
    {
        $this->form['roles'][] = $this->blankRole();
    }

    public function removeRole(int $index): void
    {
        unset($this->form['roles'][$index]);
        $this->form['roles'] = array_values($this->form['roles']);
    }

    public function addEvent(): void
    {
        $this->form['events'][] = ['t' => '', 'scope' => 'all'];
    }

    public function removeEvent(int $index): void
    {
        unset($this->form['events'][$index]);
        $this->form['events'] = array_values($this->form['events']);
    }

    public function save(): void
    {
        $scene = $this->editingId ? MashhadScene::findOrFail($this->editingId) : new MashhadScene(['source' => 'admin']);
        $minRoles = (int) config('mashhad.limits.min_players');

        $this->validate([
            'form.key' => ['required', 'regex:/^[a-z0-9_\-]{2,60}$/', Rule::unique('mashhad_scenes', 'key')->ignore($scene->id)],
            'form.category' => ['required', 'in:'.implode(',', array_keys(config('mashhad.categories')))],
            'form.title' => ['required', 'string', 'max:200'],
            'form.setup' => ['required', 'string', 'max:600'],
            'form.roles' => ['required', 'array', "min:{$minRoles}", 'max:30'],
            'form.roles.*.n' => ['required', 'string', 'max:40'],
            'form.roles.*.g' => ['required', 'string', 'max:200'],
            'form.roles.*.b' => ['nullable', 'string', 'max:200'],
            'form.roles.*.s' => ['nullable', 'string', 'max:200'],
            'form.roles.*.d' => ['required', 'in:easy,medium,hard'],
            'form.events' => ['array', 'max:20'],
            'form.events.*.t' => ['required', 'string', 'max:200'],
            'form.events.*.scope' => ['required', 'in:all,one'],
        ], [
            'form.roles.min' => "المشهد بدو {$minRoles} أدوار على الأقل — بلا تضارب أهداف ما في لعبة.",
        ], [
            'form.key' => 'المفتاح',
            'form.category' => 'المجموعة',
            'form.title' => 'العنوان',
            'form.setup' => 'القصة',
            'form.roles.*.n' => 'اسم الدور',
            'form.roles.*.g' => 'الهدف',
            'form.roles.*.b' => 'الهدف الإضافي',
            'form.roles.*.s' => 'السر',
            'form.events.*.t' => 'نص الحدث',
        ]);

        // نفس شكل أمر الاستيراد: الحقول الاختيارية الفارغة لا تُخزَّن.
        $roles = array_map(fn (array $role) => array_filter([
            'n' => trim($role['n']),
            'g' => trim($role['g']),
            'b' => trim((string) $role['b']) ?: null,
            's' => trim((string) $role['s']) ?: null,
            'd' => $role['d'],
        ], fn ($value) => $value !== null), $this->form['roles']);

        $events = array_map(fn (array $event) => [
            't' => trim($event['t']),
            'scope' => $event['scope'] === 'one' ? 'one' : 'all',
        ], $this->form['events'] ?? []);

        $isNew = ! $scene->exists;
        $fields = ['key', 'category', 'title', 'setup', 'roles', 'events'];
        $before = $isNew ? [] : $scene->only($fields);

        $scene->fill([
            'key' => $this->form['key'],
            'category' => $this->form['category'],
            'title' => trim($this->form['title']),
            'setup' => trim($this->form['setup']),
            'roles' => array_values($roles),
            'events' => array_values($events),
        ])->save();

        AdminAudit::record(
            $isNew ? 'mashhad.scene_created' : 'mashhad.scene_updated',
            ($isNew ? 'أضاف مشهد: ' : 'عدّل مشهد: ').$scene->title,
            $scene,
            AdminAudit::diff($before, $scene->only($fields)),
        );

        $this->editingId = null;
        $this->notify('انحفظ المشهد.');
    }

    public function delete(int $id): void
    {
        $scene = MashhadScene::findOrFail($id);

        AdminAudit::record('mashhad.scene_deleted', 'حذف مشهد: '.$scene->title, $scene, [
            'scene' => $scene->only(['key', 'category', 'title', 'setup', 'roles', 'events']),
        ]);

        $scene->delete();

        $this->notify('انحذف المشهد.');
    }

    public function import(): void
    {
        $this->runImport('mashhad:import', 'مشاهد المشهد');
    }

    public function render()
    {
        $term = trim($this->search);

        return view('livewire.admin.content.scenes', [
            'scenes' => MashhadScene::query()
                ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('key', 'like', "%{$term}%")
                    ->orWhere('setup', 'like', "%{$term}%")))
                ->when($this->category !== '', fn ($query) => $query->where('category', $this->category))
                ->orderBy('category')
                ->orderBy('title')
                ->paginate(20),
            'categories' => config('mashhad.categories'),
            'counts' => MashhadScene::query()->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category'),
            'levels' => HarfContent::LEVELS,
        ]);
    }
}
