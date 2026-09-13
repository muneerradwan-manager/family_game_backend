<div @if ($status === 'live') wire:poll.10s @endif>
    <div class="page-head">
        <div>
            <h1>الجلسات</h1>
            <p>كل غرف الألعاب. الجلسة العالقة بتقدر تنهيها من هون وتتحرّر قناتها.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body" style="padding-bottom:0">
            <div class="toolbar">
                <select class="select" wire:model.live="status">
                    <option value="live">الشغّالة الآن</option>
                    <option value="finished">المنتهية</option>
                    <option value="abandoned">المتروكة</option>
                    <option value="all">الكل</option>
                </select>
                <select class="select" wire:model.live="type">
                    <option value="">كل الألعاب</option>
                    @foreach ($names as $key => $presentation)
                        <option value="{{ $key }}">{{ $presentation['icon'] }} {{ $presentation['name'] }}</option>
                    @endforeach
                </select>
                @if ($status === 'live') <span class="muted small">بتتحدّث كل 10 ثواني</span> @endif
            </div>
        </div>

        @if ($games->isEmpty())
            <div class="empty"><div class="empty-emoji">🎮</div>ما في جلسات.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>اللعبة</th><th>القناة</th><th>الحالة</th><th>لاعبين</th><th>بدأها</th><th>من</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($games as $game)
                        <tr wire:key="game-{{ $game->id }}">
                            <td>
                                <a href="{{ route('admin.sessions.show', $game) }}" wire:navigate>
                                    {{ $names[$game->game_type]['icon'] ?? '🎲' }} {{ $names[$game->game_type]['name'] ?? $game->game_type }}
                                </a>
                            </td>
                            <td>{{ $game->channel?->name ?? '—' }}</td>
                            <td>@include('livewire.admin.sessions.status', ['status' => $game->status])</td>
                            <td>{{ $game->players_count }}</td>
                            <td class="small">{{ $game->starter ? '@'.$game->starter->username : '—' }}</td>
                            <td class="muted small" title="{{ $game->created_at }}">{{ $game->created_at?->diffForHumans() }}</td>
                            <td class="actions">
                                <a class="btn btn-sm" href="{{ route('admin.sessions.show', $game) }}" wire:navigate>تفاصيل</a>
                                @if ($game->isLive())
                                    <button class="btn btn-sm btn-soft-danger" wire:click="terminate('{{ $game->id }}')"
                                            wire:confirm="إنهاء الجلسة؟ اللاعبين بيطلعوا منها فوراً.">إنهاء</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $games->links() }}
        @endif
    </div>
</div>
