<div>
    <div class="page-head">
        <div>
            <h1>القنوات</h1>
            <p>قنوات العائلات: أعضاؤها، رموز دعوتها، وجلساتها.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body" style="padding-bottom:0">
            <div class="toolbar">
                <input class="input" type="search" wire:model.live.debounce.400ms="search" placeholder="🔍 اسم القناة أو رمز الدعوة">
                <span class="spacer"></span>
                <span wire:loading class="muted small">عم يحمّل…</span>
            </div>
        </div>

        @if ($channels->isEmpty())
            <div class="empty"><div class="empty-emoji">👨‍👩‍👧</div>ما في قنوات.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>القناة</th>
                        <th>الصاحب</th>
                        <th>الرمز</th>
                        <th>الأعضاء</th>
                        <th>الجلسات</th>
                        <th>الآن</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($channels as $channel)
                        <tr wire:key="channel-{{ $channel->id }}">
                            <td><strong>{{ $channel->name }}</strong></td>
                            <td class="small">{{ $channel->owner ? '@'.$channel->owner->username : '—' }}</td>
                            <td class="mono">{{ $channel->invite_code }}</td>
                            <td>{{ $channel->members_count }}</td>
                            <td>{{ $channel->games_count }}</td>
                            <td>
                                @if ($channel->activeGame && in_array($channel->activeGame->status, ['lobby', 'playing']))
                                    <span class="badge b-green">🎮 {{ $channel->activeGame->game_type }}</span>
                                @else
                                    <span class="muted small">—</span>
                                @endif
                            </td>
                            <td class="actions">
                                <button class="btn btn-sm" wire:click="view('{{ $channel->id }}')">تفاصيل</button>
                                <button class="btn btn-sm btn-soft-danger" wire:click="delete('{{ $channel->id }}')"
                                        wire:confirm="حذف القناة «{{ $channel->name }}» مع كل سجلها؟ ما في رجعة.">حذف</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $channels->links() }}
        @endif
    </div>

    @if ($viewing)
        <div class="modal-backdrop" wire:click.self="$set('viewingId', null)">
            <div class="modal modal-lg">
                <div class="modal-head">
                    <h3>{{ $viewing->name }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('viewingId', null)">✕</button>
                </div>
                <div class="modal-body stack">
                    <form class="row" wire:submit="rename">
                        <div class="field" style="flex:1">
                            <label>اسم القناة</label>
                            <input class="input" wire:model="name" maxlength="60">
                            @error('name') <span class="error">{{ $message }}</span> @enderror
                        </div>
                        <button class="btn btn-primary" type="submit" style="align-self:flex-end">حفظ الاسم</button>
                    </form>

                    <div class="row">
                        <span>رمز الدعوة: <strong class="mono">{{ $viewing->invite_code }}</strong></span>
                        <button class="btn btn-sm" wire:click="regenerateInvite('{{ $viewing->id }}')"
                                wire:confirm="الرمز القديم بيبطل. متأكد؟">تجديد الرمز</button>
                        <span class="spacer"></span>
                        @if ($viewing->active_game_id)
                            <button class="btn btn-sm btn-soft-danger" wire:click="endActiveGame('{{ $viewing->id }}')"
                                    wire:confirm="إنهاء اللعبة الشغّالة بهالقناة؟">إنهاء اللعبة الشغّالة</button>
                        @endif
                    </div>

                    <div class="card">
                        <div class="card-head"><h3>الأعضاء ({{ $viewing->members->count() }})</h3></div>
                        <div class="table-wrap">
                            <table class="table">
                                <tbody>
                                @foreach ($viewing->members as $member)
                                    <tr wire:key="member-{{ $member->id }}">
                                        <td>
                                            {{ $member->full_name }}
                                            <span class="muted small mono">{{ '@'.$member->username }}</span>
                                        </td>
                                        <td>
                                            @if ($member->id === $viewing->owner_id)
                                                <span class="badge b-blue">الصاحب</span>
                                            @endif
                                            @if ($member->banned_at)
                                                <span class="badge b-red">موقوف</span>
                                            @endif
                                        </td>
                                        <td class="muted small">{{ \Illuminate\Support\Carbon::parse($member->pivot->joined_at)->format('Y-m-d') }}</td>
                                        <td class="actions">
                                            @if ($member->id !== $viewing->owner_id)
                                                <button class="btn btn-sm btn-soft-danger" wire:click="removeMember('{{ $member->id }}')"
                                                        wire:confirm="شيل {{ '@'.$member->username }} من القناة؟">شيل</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head"><h3>آخر الجلسات ({{ $viewing->games_count }})</h3></div>
                        @if ($recentGames->isEmpty())
                            <div class="empty">ما لعبوا بعد.</div>
                        @else
                            <div class="table-wrap">
                                <table class="table">
                                    <tbody>
                                    @foreach ($recentGames as $game)
                                        <tr>
                                            <td><a href="{{ route('admin.sessions.show', $game) }}" wire:navigate>{{ $game->game_type }}</a></td>
                                            <td>@include('livewire.admin.sessions.status', ['status' => $game->status])</td>
                                            <td class="muted small">{{ $game->created_at?->format('Y-m-d H:i') }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
