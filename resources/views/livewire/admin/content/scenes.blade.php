<div>
    <div class="page-head">
        <div>
            <h1>🎬 مشاهد المشهد</h1>
            <p>كل مشهد: قصة، أدوار بأهداف متضاربة، وأحداث مفاجئة.</p>
        </div>
        <div class="page-actions">
            <a class="btn" href="{{ route('admin.content.mashhad-extras') }}" wire:navigate>🎭 أدوار الحشو والجوائز</a>
            <button class="btn btn-primary" wire:click="create">+ مشهد</button>
        </div>
    </div>

    <div class="row" style="margin-bottom:14px">
        @foreach ($categories as $key => $cat)
            <button type="button" class="btn btn-sm {{ $category === $key ? 'btn-primary' : '' }}"
                    wire:click="$set('category', '{{ $category === $key ? '' : $key }}')">
                {{ $cat['emoji'] ?? '' }} {{ $cat['label'] }} <span class="badge">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="padding-bottom:0">
            <div class="toolbar">
                <input class="input" type="search" wire:model.live.debounce.400ms="search" placeholder="🔍 عنوان أو مفتاح أو قصة">
            </div>
        </div>

        @if ($scenes->isEmpty())
            <div class="empty"><div class="empty-emoji">🎬</div>ما في مشاهد بهالفلتر.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>المشهد</th><th>المجموعة</th><th>الأدوار</th><th>الأحداث</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($scenes as $scene)
                        <tr wire:key="scene-{{ $scene->id }}">
                            <td style="max-width:420px">
                                <strong>{{ $scene->title }}</strong> <span class="muted small mono">{{ $scene->key }}</span>
                                <div class="muted small">{{ \Illuminate\Support\Str::limit($scene->setup, 120) }}</div>
                            </td>
                            <td class="small">{{ $categories[$scene->category]['emoji'] ?? '' }} {{ $categories[$scene->category]['label'] ?? $scene->category }}</td>
                            <td>{{ count($scene->roles ?? []) }}</td>
                            <td>{{ count($scene->events ?? []) }}</td>
                            <td class="actions">
                                <button class="btn btn-sm" wire:click="edit({{ $scene->id }})">تعديل</button>
                                <button class="btn btn-sm btn-soft-danger" wire:click="delete({{ $scene->id }})" wire:confirm="حذف المشهد «{{ $scene->title }}»؟">حذف</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $scenes->links() }}
        @endif
    </div>

    @include('livewire.admin.content.partials.import', [
        'formatHint' => 'ملف JSONL: سطر لكل مشهد بصيغة <code>{"key":"blackout","c":"daily","title":"…","setup":"…","roles":[{"n":"الأب","g":"الهدف","b":"إضافي","s":"سر","d":"medium"}],"events":[{"t":"…","scope":"all"}]}</code>. المشهد الموجود بنفس المفتاح بيتحدّث.',
    ])

    @if ($editingId !== null)
        <div class="modal-backdrop" wire:click.self="$set('editingId', null)">
            <form class="modal modal-lg" wire:submit="save">
                <div class="modal-head">
                    <h3>{{ $editingId ? 'تعديل مشهد' : 'مشهد جديد' }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('editingId', null)">✕</button>
                </div>
                <div class="modal-body stack">
                    <div class="form-grid">
                        <div class="field">
                            <label>العنوان</label>
                            <input class="input" wire:model="form.title" maxlength="200">
                            @error('form.title') <span class="error">{{ $message }}</span> @enderror
                        </div>
                        <div class="field">
                            <label>المجموعة</label>
                            <select class="select" wire:model="form.category">
                                @foreach ($categories as $key => $cat)
                                    <option value="{{ $key }}">{{ $cat['emoji'] ?? '' }} {{ $cat['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label>المفتاح</label>
                            <input class="input ltr mono" wire:model="form.key" maxlength="60" placeholder="blackout">
                            <span class="hint">فريد — الاستيراد بيحدّث المشهد بنفس المفتاح.</span>
                            @error('form.key') <span class="error">{{ $message }}</span> @enderror
                        </div>
                        <div class="field full">
                            <label>القصة (بتنقرأ للكل)</label>
                            <textarea class="textarea" wire:model="form.setup" maxlength="600"></textarea>
                            @error('form.setup') <span class="error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div>
                        <div class="row" style="margin-bottom:8px">
                            <h3 style="margin:0;font-size:15px">الأدوار ({{ count($form['roles'] ?? []) }})</h3>
                            <span class="spacer"></span>
                            <button type="button" class="btn btn-sm" wire:click="addRole">+ دور</button>
                        </div>
                        @error('form.roles') <div class="error" style="margin-bottom:8px">{{ $message }}</div> @enderror
                        @foreach ($form['roles'] ?? [] as $index => $role)
                            <div class="repeater-item" wire:key="role-{{ $index }}">
                                <div class="form-grid">
                                    <div class="field">
                                        <label>الدور</label>
                                        <input class="input" wire:model="form.roles.{{ $index }}.n" maxlength="40">
                                        @error("form.roles.$index.n") <span class="error">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field">
                                        <label>الصعوبة</label>
                                        <div class="row">
                                            <select class="select" wire:model="form.roles.{{ $index }}.d" style="flex:1">
                                                @foreach ($levels as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" class="btn btn-sm btn-soft-danger" wire:click="removeRole({{ $index }})">حذف</button>
                                        </div>
                                    </div>
                                    <div class="field full">
                                        <label>🎯 الهدف الرئيسي</label>
                                        <input class="input" wire:model="form.roles.{{ $index }}.g" maxlength="200">
                                        @error("form.roles.$index.g") <span class="error">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field">
                                        <label>⭐ هدف إضافي (اختياري)</label>
                                        <input class="input" wire:model="form.roles.{{ $index }}.b" maxlength="200">
                                    </div>
                                    <div class="field">
                                        <label>🤫 سر (اختياري)</label>
                                        <input class="input" wire:model="form.roles.{{ $index }}.s" maxlength="200">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div>
                        <div class="row" style="margin-bottom:8px">
                            <h3 style="margin:0;font-size:15px">الأحداث المفاجئة ({{ count($form['events'] ?? []) }})</h3>
                            <span class="spacer"></span>
                            <button type="button" class="btn btn-sm" wire:click="addEvent">+ حدث</button>
                        </div>
                        @foreach ($form['events'] ?? [] as $index => $event)
                            <div class="row" wire:key="event-{{ $index }}" style="margin-bottom:8px;align-items:flex-start">
                                <div class="field" style="flex:1">
                                    <input class="input" wire:model="form.events.{{ $index }}.t" maxlength="200" placeholder="🚪 حدا عم يدق عالباب!">
                                    @error("form.events.$index.t") <span class="error">{{ $message }}</span> @enderror
                                </div>
                                <select class="select" wire:model="form.events.{{ $index }}.scope" style="width:150px">
                                    <option value="all">للكل</option>
                                    <option value="one">للاعب واحد سرّاً</option>
                                </select>
                                <button type="button" class="btn btn-sm btn-soft-danger" wire:click="removeEvent({{ $index }})">✕</button>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn btn-primary" type="submit">حفظ المشهد</button>
                    <button class="btn" type="button" wire:click="$set('editingId', null)">إلغاء</button>
                </div>
            </form>
        </div>
    @endif
</div>
