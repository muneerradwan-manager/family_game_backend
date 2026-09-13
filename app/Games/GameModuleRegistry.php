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
}
