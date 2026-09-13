<div>
    <div class="page-head">
        <div>
            <h1>المشرفون</h1>
            <p>حسابات لوحة الإدارة. المشرف الأعلى وحده بيقدر يدير المشرفين.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" wire:click="create">+ مشرف جديد</button>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>الاسم</th><th>الإيميل</th><th>الصلاحية</th><th>آخر دخول</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($admins as $row)
                    <tr wire:key="admin-{{ $row->id }}">
                        <td><strong>{{ $row->name }}</strong> @if ($row->id === auth('admin')->id()) <span class="badge">أنت</span> @endif</td>
                        <td class="mono">{{ $row->email }}</td>
                        <td><span class="badge {{ $row->is_super ? 'b-blue' : '' }}">{{ $row->is_super ? 'مشرف أعلى' : 'مشرف' }}</span></td>
                        <td class="muted small">{{ $row->last_login_at?->diffForHumans() ?? 'ما دخل بعد' }}</td>
                        <td class="actions">
                            <button class="btn btn-sm" wire:click="edit({{ $row->id }})">تعديل</button>
                            @if ($row->id !== auth('admin')->id())
                                <button class="btn btn-sm btn-soft-danger" wire:click="delete({{ $row->id }})"
                                        wire:confirm="حذف المشرف {{ $row->email }}؟">حذف</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($editingId !== null)
        <div class="modal-backdrop" wire:click.self="$set('editingId', null)">
            <form class="modal" wire:submit="save">
                <div class="modal-head">
                    <h3>{{ $editingId ? 'تعديل مشرف' : 'مشرف جديد' }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('editingId', null)">✕</button>
                </div>
                <div class="modal-body form-grid">
                    <div class="field">
                        <label>الاسم</label>
                        <input class="input" wire:model="form.name">
                        @error('form.name') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label>الإيميل</label>
                        <input class="input ltr" type="email" wire:model="form.email">
                        @error('form.email') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field full">
                        <label>{{ $editingId ? 'كلمة سر جديدة (فاضي = بدون تغيير)' : 'كلمة السر' }}</label>
                        <input class="input ltr" type="password" wire:model="form.password" autocomplete="new-password">
                        <span class="hint">10 أحرف على الأقل.</span>
                        @error('form.password') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field full">
                        <label class="check"><input type="checkbox" wire:model="form.is_super"> <span>مشرف أعلى (بيدير المشرفين)</span></label>
                        @error('form.is_super') <span class="error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn btn-primary" type="submit">حفظ</button>
                    <button class="btn" type="button" wire:click="$set('editingId', null)">إلغاء</button>
                </div>
            </form>
        </div>
    @endif
</div>
