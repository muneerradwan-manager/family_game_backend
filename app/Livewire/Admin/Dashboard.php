<?php

namespace App\Livewire\Admin;

use App\Games\GameModuleRegistry;
use App\Models\AdminAuditLog;
use App\Models\Channel;
use App\Models\Game;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

#[Layout('admin.layout')]
#[Title('نظرة عامة')]
class Dashboard extends AdminComponent
{
    public function render(GameModuleRegistry $registry)
    {
        $gameNames = [];

        foreach ($registry->all() as $type => $module) {
            $gameNames[$type] = $registry->presentation($module);
        }

        return view('livewire.admin.dashboard', [
            'stats' => [
                'users' => User::count(),
                'newUsers' => User::where('created_at', '>=', now()->subDays(7))->count(),
                'banned' => User::whereNotNull('banned_at')->count(),
                'channels' => Channel::count(),
                'live' => Game::whereIn('status', [Game::STATUS_LOBBY, Game::STATUS_PLAYING])->count(),
                'finishedToday' => Game::where('status', Game::STATUS_FINISHED)
                    ->where('finished_at', '>=', now()->startOfDay())
                    ->count(),
            ],
            'byType' => Game::query()
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('game_type, count(*) as total')
                ->groupBy('game_type')
                ->pluck('total', 'game_type'),
            'liveGames' => Game::query()
                ->whereIn('status', [Game::STATUS_LOBBY, Game::STATUS_PLAYING])
                ->with('channel')
                ->withCount('players')
                ->latest()
                ->limit(8)
                ->get(),
            'recentAudit' => AdminAuditLog::query()->latest('created_at')->limit(8)->get(),
            'gameNames' => $gameNames,
            'platform' => [
                'maintenance' => (bool) config('platform.maintenance.enabled'),
                'registration' => (bool) config('platform.registration.enabled'),
                'minVersion' => config('platform.min_app_version.version'),
            ],
        ]);
    }
}
