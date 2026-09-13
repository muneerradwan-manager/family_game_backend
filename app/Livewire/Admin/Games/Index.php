<?php

namespace App\Livewire\Admin\Games;

use App\Games\GameModuleRegistry;
use App\Livewire\Admin\AdminComponent;
use App\Livewire\Admin\Concerns\EditsSettings;
use App\Models\Game;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * كتالوج الألعاب: تفعيل/إيقاف، والاسم والوصف والأيقونة كما يراها التطبيق.
 */
#[Layout('admin.layout')]
#[Title('الألعاب')]
class Index extends AdminComponent
{
    use EditsSettings;

    public ?string $editingType = null;

    /** @var array{name: string, description: string, icon: string} */
    public array $form = ['name' => '', 'description' => '', 'icon' => ''];

    public function toggle(string $type, GameModuleRegistry $registry): void
    {
        abort_unless($registry->has($type), 404);

        $name = $registry->presentation($registry->get($type))['name'];
        $key = "games.catalog.{$type}.enabled";

        if ($registry->isEnabled($type)) {
            $this->saveSetting($key, false, "تفعيل لعبة {$name}");
            $this->notify("انوقفت «{$name}» — الجلسات الشغّالة بتكمل، بس ما في غرف جديدة.", 'warning');
        } else {
            // مفعّلة = الافتراضي: نحذف التعديل بدل تخزين true.
            $this->resetSetting($key, "تفعيل لعبة {$name}");
            $this->notify("رجعت «{$name}» متاحة بالتطبيق.");
        }
    }

    public function edit(string $type, GameModuleRegistry $registry): void
    {
        abort_unless($registry->has($type), 404);

        $this->editingType = $type;
        $this->form = $registry->presentation($registry->get($type));
        $this->resetValidation();
    }

    public function save(GameModuleRegistry $registry): void
    {
        abort_unless($this->editingType && $registry->has($this->editingType), 404);

        $this->validate([
            'form.name' => ['required', 'string', 'max:40'],
            'form.description' => ['required', 'string', 'max:300'],
            'form.icon' => ['required', 'string', 'max:8'],
        ], [], [
            'form.name' => 'الاسم',
            'form.description' => 'الوصف',
            'form.icon' => 'الأيقونة',
        ]);

        $module = $registry->get($this->editingType);
        $originals = ['name' => $module->name(), 'description' => $module->description(), 'icon' => $module->icon()];
        $labels = ['name' => 'اسم', 'description' => 'وصف', 'icon' => 'أيقونة'];

        foreach ($originals as $field => $original) {
            $key = "games.catalog.{$this->editingType}.{$field}";
            $value = trim($this->form[$field]);
            $label = "{$labels[$field]} لعبة {$original}";

            // مطابق لتعريف الوحدة = لا تعديل.
            $value === $original ? $this->resetSetting($key, $label) : $this->saveSetting($key, $value, $label);
        }

        $this->editingType = null;
        $this->notify('انحفظ — بيظهر بالتطبيق مع أول تحديث لقائمة الألعاب.');
    }

    public function render(GameModuleRegistry $registry)
    {
        $counts = Game::query()
            ->selectRaw('game_type, count(*) as total, sum(case when status in (?, ?) then 1 else 0 end) as live', [Game::STATUS_LOBBY, Game::STATUS_PLAYING])
            ->groupBy('game_type')
            ->get()
            ->keyBy('game_type');

        $contentRoutes = [
            'harf' => 'admin.content.harf',
            'spy' => 'admin.content.spy',
            'hadaf' => 'admin.content.hadaf',
            'mashhad' => 'admin.content.scenes',
        ];

        $games = [];

        foreach ($registry->all() as $type => $module) {
            $games[] = [
                'type' => $type,
                'presentation' => $registry->presentation($module),
                'enabled' => $registry->isEnabled($type),
                'players' => $module->minPlayers().'–'.$module->maxPlayers(),
                'total' => (int) ($counts[$type]->total ?? 0),
                'live' => (int) ($counts[$type]->live ?? 0),
                'contentRoute' => $contentRoutes[$type] ?? null,
                'customized' => collect(['name', 'description', 'icon'])
                    ->contains(fn ($field) => $this->settings()->isOverridden("games.catalog.{$type}.{$field}")),
            ];
        }

        return view('livewire.admin.games.index', ['games' => $games]);
    }
}
