<div>
    <div class="page-head">
        <div>
            <h1>سجل التدقيق</h1>
            <p>كل تعديل من اللوحة: مين عمله، ومتى، وشو تغيّر.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body" style="padding-bottom:0">
            <div class="toolbar">
                <input class="input" type="search" wire:model.live.debounce.400ms="search" placeholder="🔍 وصف، إيميل مشرف، أو معرّف">
                <select class="select" wire:model.live="action">
                    <option value="">كل الأفعال</option>
                    @foreach ($actions as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($logs->isEmpty())
            <div class="empty"><div class="empty-emoji">🧾</div>السجل فاضي.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>الوقت</th><th>المشرف</th><th>الفعل</th><th>الوصف</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="muted small" title="{{ $log->created_at }}">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="small mono">{{ $log->admin_email ?? 'النظام' }}</td>
                            <td><span class="badge">{{ $log->action }}</span></td>
                            <td>{{ $log->summary }}</td>
                            <td class="actions">
                                @if ($log->changes)
                                    <button class="btn btn-sm" wire:click="$set('viewingId', {{ $log->id }})">التفاصيل</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $logs->links() }}
        @endif
    </div>

    @if ($viewing)
        <div class="modal-backdrop" wire:click.self="$set('viewingId', null)">
            <div class="modal">
                <div class="modal-head">
                    <h3>{{ $viewing->summary }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('viewingId', null)">✕</button>
                </div>
                <div class="modal-body stack">
                    <div class="muted small">
                        {{ $viewing->admin_email ?? 'النظام' }} · {{ $viewing->created_at }} · IP <span class="mono">{{ $viewing->ip }}</span>
                        @if ($viewing->subject_type) · {{ $viewing->subject_type }} <span class="mono">{{ $viewing->subject_id }}</span> @endif
                    </div>
                    <pre class="json">{{ json_encode($viewing->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            </div>
        </div>
    @endif
</div>
