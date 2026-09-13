<?php

namespace App\Games;

use InvalidArgumentException;

/**
 * سجل الألعاب المتاحة. شاشة "اختيار اللعبة" تُبنى منه مباشرة،
 * فإضافة لعبة = تسجيل وحدتها هنا لا أكثر.
 */
class GameModuleRegistry
{
    /** @var array<string, GameModule> */
    private array $modules = [];

    /** @param iterable<GameModule> $modules */
    public function __construct(iterable $modules = [])
    {
        foreach ($modules as $module) {
            $this->register($module);
        }
    }

    public function register(GameModule $module): void
    {
        $this->modules[$module->type()] = $module;
    }

    public function has(string $type): bool
    {
        return isset($this->modules[$type]);
    }

    public function get(string $type): GameModule
    {
        return $this->modules[$type]
            ?? throw new InvalidArgumentException("لا توجد لعبة بالمعرّف [{$type}].");
    }

    /** @return array<string, GameModule> */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * الألعاب المفعّلة من لوحة الإدارة — ما يظهر في شاشة الاختيار ويُسمح بفتحه.
     *
     * إيقاف لعبة لا يمسّ جلساتها الجارية: من بدأ يكمل، لكن لا غرفة جديدة.
     *
     * @return array<string, GameModule>
     */
    public function enabled(): array
    {
        return array_filter(
            $this->modules,
            fn (GameModule $module) => $this->isEnabled($module->type()),
        );
    }

    public function isEnabled(string $type): bool
    {
        return $this->has($type) && (bool) config("games.catalog.{$type}.enabled", true);
    }

    /**
     * الاسم والوصف والأيقونة كما يعرضها التطبيق.
     *
     * المشرف يعدّلها من اللوحة؛ ما لم يعدّله يبقى كما عرّفته وحدة اللعبة.
     *
     * @return array{name: string, description: string, icon: string}
     */
    public function presentation(GameModule $module): array
    {
        $type = $module->type();

        return [
            'name' => $this->override("games.catalog.{$type}.name") ?? $module->name(),
            'description' => $this->override("games.catalog.{$type}.description") ?? $module->description(),
            'icon' => $this->override("games.catalog.{$type}.icon") ?? $module->icon(),
        ];
    }

    private function override(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
