<?php

namespace App\Livewire\Admin\Sessions;

use App\Admin\AdminAudit;
use App\Games\GameModuleRegistry;
use App\Games\GameTerminator;
use App\Games\State\GameStateStore;
use App\Livewire\Admin\AdminComponent;
use App\Models\Game;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

#[Layout('admin.layout')]
#[Title('تفاصيل الجلسة')]
class Show extends AdminComponent
{
    public Game $game;

    public bool $showState = false;

    public function mount(Game $game): void
    {
        $this->game = $game;
    }

    public function terminate(GameTerminator $terminator): void
    {
        $this->game->refresh();

        if (! $terminator->terminate($this->game)) {
            $this->notify('الجلسة منتهية أصلاً.', 'warning');

            return;
        }

        AdminAudit::record('game.terminated', "أنهى جلسة {$this->game->game_type}", $this->game);

        $this->game->refresh();
        $this->notify('انتهت الجلسة.');
    }

    public function render(GameModuleRegistry $registry, GameStateStore $store)
    {
        $this->game->refresh()->load(['channel', 'starter', 'players.user']);

        $presentation = $registry->has($this->game->game_type)
            ? $registry->presentation($registry->get($this->game->game_type))
            : ['name' => $this->game->game_type, 'icon' => '🎲', 'description' => ''];

        // الحالة الحيّة فيها أسرار اللعبة (الجاسوس، الأدوار). تُعرض عند الطلب
        // فقط، ولا تُحمَّل أصلاً ما لم يطلبها المشرف.
        $state = $this->showState ? $store->get($this->game->id) : null;

        return view('livewire.admin.sessions.show', [
            'presentation' => $presentation,
            'state' => $state,
            'json' => fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
