<div>
    <div class="page-head">
        <div>
            <h1>الإعلانات</h1>
            <p>رسائل بتظهر أعلى الشاشة الرئيسية بالتطبيق — للكل أو لقناة وحدة، ومع إشعار إذا بدك.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" wire:click="create">+ إعلان جديد</button>
        </div>
    </div>

    <div class="card">
        @if ($announcements->isEmpty())
            <div class="empty"><div class="empty-emoji">📣</div>ما في إعلانات.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>الإعلان</th><th>لمين</th><th>المدة</th><th>الحالة</th><th>إشعار</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($announcements as $announcement)
                        @php
                            $levelClass = ['info' => 'b-blue', 'success' => 'b-green', 'warning' => 'b-amber', 'danger' => 'b-red'][$announcement->level] ?? '';
                            $live = $announcement->is_active
                                && (! $announcement->starts_at || $announcement->starts_at->lte($now))
                                && (! $announcement->ends_at || $announcement->ends_at->gt($now));
                        @endphp
                        <tr wire:key="ann-{{ $announcement->id }}">
                            <td style="max-width:360px">
                                <span class="badge {{ $levelClass }}">{{ $levels[$announcement->level] ?? $announcement->level }}</span>
                                <strong>{{ $announcement->title }}</strong>
                                <div class="muted small">{{ \Illuminate\Support\Str::limit($announcement->body, 110) }}</div>
                            </td>
                            <td class="small">{{ $announcement->audience === 'all' ? 'الكل' : '👨‍👩‍👧 '.($announcement->channel?->name ?? 'قناة محذوفة') }}</td>
                            <td class="muted small">
                                {{ $announcement->starts_at?->format('m-d H:i') ?? 'فوراً' }}
                                →
                                {{ $announcement->ends_at?->format('m-d H:i') ?? 'بلا نهاية' }}
                            </td>
                            <td>
                                @if ($live)
                                    <span class="badge b-green">ظاهر الآن</span>
                                @elseif (! $announcement->is_active)
                                    <span class="badge">موقوف</span>
                                @elseif ($announcement->ends_at && $announcement->ends_at->lte($now))
                                    <span class="badge">انتهى</span>
                                @else
                                    <span class="badge b-amber">مجدول</span>
                                @endif
                            </td>
                            <td class="muted small">{{ $announcement->push_sent_at?->diffForHumans() ?? '—' }}</td>
                            <td class="actions">
                                <button class="btn btn-sm" wire:click="edit({{ $announcement->id }})">تعديل</button>
                                <button class="btn btn-sm" wire:click="toggleActive({{ $announcement->id }})">{{ $announcement->is_active ? 'إيقاف' : 'تفعيل' }}</button>
                                <button class="btn btn-sm" wire:click="push({{ $announcement->id }})"
                                        wire:confirm="{{ $announcement->push_sent_at ? 'انبعت إشعار قبل. تبعته مرة تانية؟' : 'بعت إشعار لكل المستهدفين؟' }}">🔔 إشعار</button>
                                <button class="btn btn-sm btn-soft-danger" wire:click="delete({{ $announcement->id }})" wire:confirm="حذف الإعلان؟">حذف</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $announcements->links() }}
        @endif
    </div>

    @if ($editingId !== null)
        <div class="modal-backdrop" wire:click.self="$set('editingId', null)">
            <form class="modal" wire:submit="save">
                <div class="modal-head">
                    <h3>{{ $editingId ? 'تعديل الإعلان' : 'إعلان جديد' }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('editingId', null)">✕</button>
                </div>
                <div class="modal-body form-grid">
                    <div class="field full">
                        <label>العنوان</label>
                        <input class="input" wire:model="form.title" maxlength="120">
                        @error('form.title') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field full">
                        <label>النص</label>
                        <textarea class="textarea" wire:model="form.body" maxlength="2000"></textarea>
                        @error('form.body') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label>النوع</label>
                        <select class="select" wire:model="form.level">
                            @foreach ($levels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label>لمين</label>
                        <select class="select" wire:model.live="form.audience">
                            <option value="all">كل المستخدمين</option>
                            <option value="channel">أعضاء قناة وحدة</option>
                        </select>
                    </div>
                    @if (($form['audience'] ?? 'all') === 'channel')
                        <div class="field full">
                            <label>رمز دعوة القناة</label>
                            <input class="input ltr mono" wire:model="form.channel_code" maxlength="6" placeholder="ABC123">
                            @error('form.channel_code') <span class="error">{{ $message }}</span> @enderror
                        </div>
                    @endif
                    <div class="field">
                        <label>يبلّش (اختياري)</label>
                        <input class="input ltr" type="datetime-local" wire:model="form.starts_at">
                        @error('form.starts_at') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label>ينتهي (اختياري)</label>
                        <input class="input ltr" type="datetime-local" wire:model="form.ends_at">
                        @error('form.ends_at') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field full row">
                        <label class="check"><input type="checkbox" wire:model="form.is_active"> <span>مفعّل</span></label>
                        <label class="check"><input type="checkbox" wire:model="form.is_dismissible"> <span>المستخدم بيقدر يسكّره</span></label>
                        <label class="check"><input type="checkbox" wire:model="form.send_push"> <span>🔔 ابعت إشعار مع الحفظ</span></label>
                    </div>
                    <p class="hint full" style="margin:0">الأوقات بتوقيت السيرفر ({{ config('app.timezone') }}).</p>
                </div>
                <div class="modal-foot">
                    <button class="btn btn-primary" type="submit">حفظ</button>
                    <button class="btn" type="button" wire:click="$set('editingId', null)">إلغاء</button>
                    <span wire:loading wire:target="save" class="muted small">عم يحفظ…</span>
                </div>
            </form>
        </div>
    @endif
</div>
