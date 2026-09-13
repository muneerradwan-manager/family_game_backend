<div @if ($game->isLive()) wire:poll.5s @endif>
    <div class="page-head">
        <div>
            <h1>{{ $presentation['icon'] }} {{ $presentation['name'] }}</h1>
            <p>
                @include('livewire.admin.sessions.status', ['status' => $game->status])
                · القناة: <strong>{{ $game->channel?->name ?? 'محذوفة' }}</strong>
                · <span class="mono small">{{ $game->id }}</span>
            </p>
        </div>
        <div class="page-actions">
            <a class="btn" href="{{ route('admin.sessions.index') }}" wire:navigate>← الجلسات</a>
            @if ($game->isLive())
                <button class="btn btn-danger" wire:click="terminate" wire:confirm="إنهاء الجلسة؟ اللاعبين بيطلعوا منها فوراً.">إنهاء الجلسة</button>
            @endif
        </div>
    </div>

    <div class="grid grid-4" style="margin-bottom:16px">
        <div class="card stat"><div class="stat-label">بدأها</div><div class="stat-value" style="font-size:18px">{{ $game->starter ? '@'.$game->starter->username : '—' }}</div></div>
        <div class="card stat"><div class="stat-label">انفتحت</div><div class="stat-value" style="font-size:18px">{{ $game->created_at?->format('Y-m-d H:i') }}</div></div>
        <div class="card stat"><div class="stat-label">بدأ اللعب</div><div class="stat-value" style="font-size:18px">{{ $game->started_at?->format('H:i:s') ?? '—' }}</div></div>
        <div class="card stat"><div class="stat-label">انتهت</div><div class="stat-value" style="font-size:18px">{{ $game->finished_at?->format('H:i:s') ?? '—' }}</div></div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>اللاعبون ({{ $game->players->count() }})</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>اللاعب</th><th>النقاط</th><th>الحالة</th></tr></thead>
                    <tbody>
                    @forelse ($game->players->sortByDesc('final_score') as $player)
                        <tr>
                            <td class="muted">{{ $player->join_order }}</td>
                            <td>
                                {{ $player->user?->full_name ?? 'محذوف' }}
                                <span class="muted small mono">{{ $player->user ? '@'.$player->user->username : '' }}</span>
                            </td>
                            <td><strong>{{ $player->final_score ?? '—' }}</strong></td>
                            <td>
                                @if ($player->is_spectator) <span class="badge">متفرّج</span> @endif
                                @if ($player->left_at) <span class="badge b-amber">طلع</span> @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty">ما في لاعبين.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-head"><h2>الشروط</h2></div>
                <div class="card-body"><pre class="json">{{ $json($game->config) }}</pre></div>
            </div>
            @if ($game->result)
                <div class="card">
                    <div class="card-head"><h2>النتيجة</h2></div>
                    <div class="card-body"><pre class="json">{{ $json($game->result) }}</pre></div>
                </div>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-head">
            <h2>الحالة الحيّة</h2>
            <label class="check"><input type="checkbox" wire:model.live="showState"> <span>اعرضها (فيها أسرار اللعبة)</span></label>
        </div>
        @if ($showState)
            <div class="card-body">
                @if ($state === null)
                    <div class="empty">ما في حالة حيّة — الجلسة منتهية أو انمسحت من الذاكرة.</div>
                @else
                    <pre class="json">{{ $json($state) }}</pre>
                @endif
            </div>
        @endif
    </div>
</div>
