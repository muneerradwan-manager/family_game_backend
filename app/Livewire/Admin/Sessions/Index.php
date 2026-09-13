<?php

namespace App\Livewire\Admin\Sessions;

use App\Admin\AdminAudit;
use App\Games\GameModuleRegistry;
use App\Games\GameTerminator;
use App\Livewire\Admin\AdminComponent;
use App\Models\Game;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('الجلسات')]
class Index extends AdminComponent
{
    use WithPagination;

    /** live | finished | abandoned | all */
    #[Url]
    public string $status = 'live';

    #[Url(except: '')]
    public string $type = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function terminate(string $id, GameTerminator $terminator): void
    {
        $game = Game::with('channel')->findOrFail($id);

        if (! $terminator->terminate($game)) {
            $this->notify('الجلسة منتهية أصلاً.', 'warning');

            return;
        }

        AdminAudit::record('game.terminated', "أنهى جلسة {$game->game_type} في «{$game->channel?->name}»", $game);

        $this->notify('انتهت الجلسة وانحرّرت القناة.');
    }

    public function render(GameModuleRegistry $registry)
    {
        $names = [];

        foreach ($registry->all() as $key => $module) {
            $names[$key] = $registry->presentation($module);
        }

        $games = Game::query()
            ->with(['channel:id,name', 'starter:id,username'])
            ->withCount('players')
            ->when($this->status === 'live', fn ($query) => $query->whereIn('status', [Game::STATUS_LOBBY, Game::STATUS_PLAYING]))
            ->when(in_array($this->status, [Game::STATUS_FINISHED, Game::STATUS_ABANDONED], true), fn ($query) => $query->where('status', $this->status))
            ->when($this->type !== '', fn ($query) => $query->where('game_type', $this->type))
            ->latest()
            ->paginate(25);

        return view('livewire.admin.sessions.index', ['games' => $games, 'names' => $names]);
    }
}
