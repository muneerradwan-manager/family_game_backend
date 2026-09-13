<div>
    <div class="page-head">
        <div>
            <h1>الثيمات</h1>
            <p>ألوان التطبيق. المفعّل منها بيظهر بشاشة الإعدادات، والافتراضي هو اللي بيفتح عليه أي جهاز جديد.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" wire:click="create">+ ثيم جديد</button>
        </div>
    </div>

    <div class="grid grid-3">
        @foreach ($themes as $theme)
            @php($c = $theme->colors ?? [])
            <div class="card" wire:key="theme-{{ $theme->id }}" style="{{ $theme->is_active ? '' : 'opacity:.6' }}">
                <div class="card-body stack" style="gap:12px">
                    <div class="theme-swatch"
                         style="background:linear-gradient(135deg, {{ $c['gradientStart'] ?? '#999' }}, {{ $c['gradientEnd'] ?? '#666' }}); color: {{ $c['onPrimary'] ?? '#fff' }}">
                        {{ $theme->name }}
                    </div>
                    <div class="row">
                        <div class="dots">
                            @foreach (['primary', 'secondary', 'accent', 'background', 'surface', 'textPrimary'] as $key)
                                <span class="dot" style="background: {{ $c[$key] ?? '#000' }}" title="{{ $colorKeys[$key] }}"></span>
                            @endforeach
                        </div>
                        <span class="spacer"></span>
                        @if ($theme->is_default) <span class="badge b-blue">الافتراضي</span> @endif
                        @if ($theme->is_dark) <span class="badge">داكن</span> @endif
                        <span class="badge {{ $theme->is_active ? 'b-green' : '' }}">{{ $theme->is_active ? 'ظاهر' : 'مخفي' }}</span>
                    </div>
                    <div class="muted small">{{ $theme->tagline }} · <span class="mono">{{ $theme->key }}</span></div>
                </div>
                <div class="card-foot row">
                    <button class="btn btn-sm" wire:click="edit({{ $theme->id }})">تعديل</button>
                    <button class="btn btn-sm" wire:click="create({{ $theme->id }})">نسخ</button>
                    @unless ($theme->is_default)
                        <button class="btn btn-sm" wire:click="makeDefault({{ $theme->id }})">افتراضي</button>
                        <button class="btn btn-sm" wire:click="toggleActive({{ $theme->id }})">{{ $theme->is_active ? 'إخفاء' : 'إظهار' }}</button>
                    @endunless
                    <span class="spacer"></span>
                    <button class="btn btn-sm btn-ghost" wire:click="move({{ $theme->id }}, -1)" title="قبل">▲</button>
                    <button class="btn btn-sm btn-ghost" wire:click="move({{ $theme->id }}, 1)" title="بعد">▼</button>
                    @unless ($theme->is_default)
                        <button class="btn btn-sm btn-soft-danger" wire:click="delete({{ $theme->id }})"
                                wire:confirm="حذف الثيم «{{ $theme->name }}»؟">حذف</button>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>

    @if ($editingId !== null)
        <div class="modal-backdrop" wire:click.self="$set('editingId', null)">
            <form class="modal modal-lg" wire:submit="save">
                <div class="modal-head">
                    <h3>{{ $editingId ? 'تعديل الثيم' : 'ثيم جديد' }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('editingId', null)">✕</button>
                </div>
                <div class="modal-body stack">
                    <div class="grid grid-2">
                        <div class="stack">
                            <div class="form-grid">
                                <div class="field">
                                    <label>الاسم</label>
                                    <input class="input" wire:model.live.debounce.300ms="form.name" maxlength="40">
                                    @error('form.name') <span class="error">{{ $message }}</span> @enderror
                                </div>
                                <div class="field">
                                    <label>المفتاح</label>
                                    <input class="input ltr" wire:model="form.key" maxlength="40">
                                    <span class="hint">الأجهزة بتحفظ اختيارها بهالمفتاح — غيّره بحذر.</span>
                                    @error('form.key') <span class="error">{{ $message }}</span> @enderror
                                </div>
                                <div class="field full">
                                    <label>وصف قصير</label>
                                    <input class="input" wire:model="form.tagline" maxlength="80">
                                    @error('form.tagline') <span class="error">{{ $message }}</span> @enderror
                                </div>
                                <div class="field">
                                    <label>الترتيب</label>
                                    <input class="input" type="number" min="0" wire:model="form.sort_order">
                                    @error('form.sort_order') <span class="error">{{ $message }}</span> @enderror
                                </div>
                                <div class="field" style="justify-content:flex-end">
                                    <label class="check"><input type="checkbox" wire:model.live="form.is_dark"> <span>ثيم داكن</span></label>
                                    <label class="check"><input type="checkbox" wire:model="form.is_active"> <span>ظاهر بالتطبيق</span></label>
                                    @error('form.is_active') <span class="error">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            {{-- معاينة حيّة تقريبية لما سيراه المستخدم. --}}
                            <div style="border-radius:16px;padding:14px;background:{{ $form['colors']['background'] ?? '#fff' }};border:1px solid {{ $form['colors']['outline'] ?? '#ddd' }}">
                                <div class="theme-swatch" style="background:linear-gradient(135deg, {{ $form['colors']['gradientStart'] ?? '#999' }}, {{ $form['colors']['gradientEnd'] ?? '#666' }});color:{{ $form['colors']['onPrimary'] ?? '#fff' }}">
                                    {{ $form['name'] ?? '' }}
                                </div>
                                <div style="margin-top:10px;padding:12px;border-radius:12px;background:{{ $form['colors']['surface'] ?? '#fff' }};color:{{ $form['colors']['textPrimary'] ?? '#000' }}">
                                    <strong>بطاقة لعبة</strong>
                                    <div style="color:{{ $form['colors']['textMuted'] ?? '#777' }};font-size:13px">نص خافت تحت العنوان</div>
                                    <div class="row" style="margin-top:8px">
                                        <span style="padding:6px 14px;border-radius:10px;background:{{ $form['colors']['primary'] ?? '#000' }};color:{{ $form['colors']['onPrimary'] ?? '#fff' }};font-weight:700">ابدأ</span>
                                        <span style="padding:6px 14px;border-radius:10px;background:{{ $form['colors']['secondary'] ?? '#000' }};color:#fff;font-weight:700">ثانوي</span>
                                        <span style="padding:6px 14px;border-radius:10px;background:{{ $form['colors']['accent'] ?? '#000' }};color:#fff;font-weight:700">تنبيه</span>
                                    </div>
                                </div>
                                <div style="margin-top:8px;padding:10px;border-radius:12px;background:{{ $form['colors']['surfaceAlt'] ?? '#eee' }};color:{{ $form['colors']['textPrimary'] ?? '#000' }};font-size:13px">بطاقة بديلة</div>
                            </div>
                        </div>

                        <div class="stack" style="gap:8px">
                            @foreach ($colorKeys as $key => $label)
                                <div class="field" wire:key="color-{{ $key }}">
                                    <label>{{ $label }} <span class="muted small mono">{{ $key }}</span></label>
                                    <div class="color-field">
                                        <input type="color" wire:model.live="form.colors.{{ $key }}">
                                        <input class="input ltr mono" wire:model.live.debounce.400ms="form.colors.{{ $key }}" maxlength="7">
                                    </div>
                                    @error("form.colors.$key") <span class="error">{{ $message }}</span> @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn btn-primary" type="submit">حفظ الثيم</button>
                    <button class="btn" type="button" wire:click="$set('editingId', null)">إلغاء</button>
                </div>
            </form>
        </div>
    @endif
</div>
