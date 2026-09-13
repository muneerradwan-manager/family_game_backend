<div>
    <div class="page-head">
        <div>
            <h1>نظرة عامة</h1>
            <p>حالة التطبيق والألعاب الآن.</p>
        </div>
        <div class="page-actions">
            <a class="btn" href="{{ route('admin.sessions.index') }}" wire:navigate>🎮 الجلسات الحيّة</a>
            <a class="btn" href="{{ route('admin.platform') }}" wire:navigate>🛠️ مفاتيح التطبيق</a>
        </div>
    </div>

    @if ($platform['maintenance'])
        <div class="callout callout-danger" style="margin-bottom:16px">
            <strong>وضع الصيانة مفعّل</strong> — التطبيق مغلق أمام كل المستخدمين.
            <a href="{{ route('admin.platform') }}" wire:navigate>غيّره</a>
        </div>
    @endif

    <div class="grid grid-3" style="margin-bottom:16px">
        <div class="card stat">
            <div class="stat-label">المستخدمون</div>
            <div class="stat-value">{{ number_format($stats['users']) }}</div>
            <div class="stat-note">+{{ $stats['newUsers'] }} آخر 7 أيام · {{ $stats['banned'] }} موقوف</div>
        </div>
        <div class="card stat">
            <div class="stat-label">القنوات</div>
            <div class="stat-value">{{ number_format($stats['channels']) }}</div>
        </div>
        <div class="card stat">
            <div class="stat-label">جلسات حيّة الآن</div>
            <div class="stat-value">{{ $stats['live'] }}</div>
            <div class="stat-note">{{ $stats['finishedToday'] }} انتهت اليوم</div>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head">
                <h2>الجلسات الحيّة</h2>
                <a class="btn btn-sm btn-ghost" href="{{ route('admin.sessions.index') }}" wire:navigate>الكل</a>
            </div>
            @if ($liveGames->isEmpty())
                <div class="empty"><div class="empty-emoji">😴</div>ما في ولا جلسة شغّالة هلق.</div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>اللعبة</th><th>القناة</th><th>الحالة</th><th>لاعبين</th></tr></thead>
                        <tbody>
                        @foreach ($liveGames as $game)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.sessions.show', $game) }}" wire:navigate>
                                        {{ $gameNames[$game->game_type]['icon'] ?? '🎲' }}
                                        {{ $gameNames[$game->game_type]['name'] ?? $game->game_type }}
                                    </a>
                                </td>
                                <td>{{ $game->channel?->name ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $game->status === 'playing' ? 'b-green' : 'b-amber' }}">
                                        {{ $game->status === 'playing' ? 'تلعب' : 'لوبي' }}
                                    </span>
                                </td>
                                <td>{{ $game->players_count }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-head"><h2>مفاتيح التطبيق</h2></div>
                <div class="card-body stack small">
                    <div class="row">
                        <span>وضع الصيانة</span><span class="spacer"></span>
                        <span class="badge {{ $platform['maintenance'] ? 'b-red' : 'b-green' }}">
                            {{ $platform['maintenance'] ? 'مفعّل' : 'مطفأ' }}
                        </span>
                    </div>
                    <div class="row">
                        <span>التسجيل</span><span class="spacer"></span>
                        <span class="badge {{ $platform['registration'] ? 'b-green' : 'b-red' }}">
                            {{ $platform['registration'] ? 'مفتوح' : 'مسكّر' }}
                        </span>
                    </div>
                    <div class="row">
                        <span>أدنى نسخة للتطبيق</span><span class="spacer"></span>
                        <span class="mono">{{ $platform['minVersion'] ?: 'بلا حدّ' }}</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>الألعاب آخر 30 يوم</h2></div>
                <div class="card-body stack small">
                    @forelse ($gameNames as $type => $presentation)
                        <div class="row">
                            <span>{{ $presentation['icon'] }} {{ $presentation['name'] }}</span>
                            <span class="spacer"></span>
                            <strong>{{ $byType[$type] ?? 0 }}</strong>
                        </div>
                    @empty
                        <div class="muted">ما في ألعاب مسجّلة.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-head">
            <h2>آخر تعديلات المشرفين</h2>
            <a class="btn btn-sm btn-ghost" href="{{ route('admin.audit.index') }}" wire:navigate>السجل كامل</a>
        </div>
        @if ($recentAudit->isEmpty())
            <div class="empty">ما في تعديلات بعد.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                    @foreach ($recentAudit as $log)
                        <tr>
                            <td>{{ $log->summary }}</td>
                            <td class="muted small">{{ $log->admin_email ?? 'النظام' }}</td>
                            <td class="muted small">{{ $log->created_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
