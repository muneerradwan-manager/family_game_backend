<div>
    <div class="page-head">
        <div>
            <h1>المستخدمون</h1>
            <p>بحث، تعديل البيانات، إيقاف الحسابات، وتسجيل الخروج من كل الأجهزة.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body" style="padding-bottom:0">
            <div class="toolbar">
                <input class="input" type="search" wire:model.live.debounce.400ms="search" placeholder="🔍 اسم، يوزر، أو رقم">
                <select class="select" wire:model.live="status">
                    <option value="">كل الحسابات</option>
                    <option value="active">النشطة</option>
                    <option value="banned">الموقوفة</option>
                </select>
                <span class="spacer"></span>
                <span wire:loading class="muted small">عم يحمّل…</span>
            </div>
        </div>

        @if ($users->isEmpty())
            <div class="empty"><div class="empty-emoji">🔎</div>ما في مستخدمين بهالبحث.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>المستخدم</th>
                        <th>الهاتف</th>
                        <th>القنوات</th>
                        <th>التسجيل</th>
                        <th>الحالة</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td>
                                <strong>{{ $user->full_name }}</strong>
                                <div class="muted small mono">{{ '@'.$user->username }}</div>
                            </td>
                            <td class="mono">{{ $user->phone }}</td>
                            <td>{{ $user->channels_count }}</td>
                            <td class="muted small">{{ $user->created_at?->format('Y-m-d') }}</td>
                            <td>
                                @if ($user->banned_at)
                                    <span class="badge b-red" title="{{ $user->ban_reason }}">موقوف</span>
                                @else
                                    <span class="badge b-green">نشط</span>
                                @endif
                            </td>
                            <td class="actions">
                                <button class="btn btn-sm" wire:click="edit('{{ $user->id }}')">تعديل</button>
                                <button class="btn btn-sm" wire:click="revokeSessions('{{ $user->id }}')"
                                        wire:confirm="تسجيل خروج هذا المستخدم من كل أجهزته؟">خروج من الأجهزة</button>
                                @if ($user->banned_at)
                                    <button class="btn btn-sm" wire:click="unban('{{ $user->id }}')">رفع الإيقاف</button>
                                @else
                                    <button class="btn btn-sm btn-soft-danger" wire:click="startBan('{{ $user->id }}')">إيقاف</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $users->links() }}
        @endif
    </div>

    @if ($editingId)
        <div class="modal-backdrop" wire:click.self="$set('editingId', null)">
            <form class="modal" wire:submit="save">
                <div class="modal-head">
                    <h3>تعديل المستخدم</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('editingId', null)">✕</button>
                </div>
                <div class="modal-body form-grid">
                    <div class="field full">
                        <label>الاسم</label>
                        <input class="input" wire:model="form.full_name">
                        @error('form.full_name') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label>اليوزر</label>
                        <input class="input ltr" wire:model="form.username">
                        <span class="hint">أحرف إنجليزية صغيرة وأرقام و _ من 3 لـ 20.</span>
                        @error('form.username') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label>رقم الهاتف</label>
                        <input class="input ltr" wire:model="form.phone" placeholder="+9627xxxxxxxx">
                        @error('form.phone') <span class="error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn btn-primary" type="submit">حفظ</button>
                    <button class="btn" type="button" wire:click="$set('editingId', null)">إلغاء</button>
                </div>
            </form>
        </div>
    @endif

    @if ($banning)
        <div class="modal-backdrop" wire:click.self="$set('banningId', null)">
            <form class="modal" wire:submit="ban">
                <div class="modal-head">
                    <h3>إيقاف {{ '@'.$banning->username }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('banningId', null)">✕</button>
                </div>
                <div class="modal-body stack">
                    <div class="callout callout-warning">
                        الموقوف ما بيقدر يدخل ولا يلعب، وبينسجّل خروجه من كل أجهزته فوراً. بتقدر ترفع الإيقاف بأي وقت.
                    </div>
                    <div class="field">
                        <label>السبب (بيظهر للمستخدم)</label>
                        <textarea class="textarea" wire:model="banReason" maxlength="300"></textarea>
                        @error('banReason') <span class="error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn btn-danger" type="submit">إيقاف الحساب</button>
                    <button class="btn" type="button" wire:click="$set('banningId', null)">إلغاء</button>
                </div>
            </form>
        </div>
    @endif
</div>
