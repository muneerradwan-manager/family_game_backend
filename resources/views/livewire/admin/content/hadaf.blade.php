<div>
    <div class="page-head">
        <div>
            <h1>🎯 أسئلة الهدف</h1>
            <p>{{ number_format($total) }} سؤال بالبنك. أسئلة الرياضيات بتتولّد تلقائياً فوق البنك.</p>
        </div>
        <div class="page-actions">
            <a class="btn" href="{{ route('admin.games.settings', 'hadaf') }}" wire:navigate>⚙️ أرقام اللعبة</a>
            <button class="btn" wire:click="$toggle('showCategories')">🏷️ أسماء المجموعات</button>
            <button class="btn btn-primary" wire:click="create">+ سؤال</button>
        </div>
    </div>

    <div class="grid grid-3" style="margin-bottom:16px">
        @foreach ($categories as $key => $cat)
            <button type="button" class="card stat" style="text-align:right;cursor:pointer;font:inherit;{{ $category === $key ? 'border-color:var(--primary)' : '' }}"
                    wire:click="$set('category', '{{ $category === $key ? '' : $key }}')">
                <div class="stat-label">{{ $cat['emoji'] ?? '' }} {{ $cat['label'] }} @if (! empty($cat['generated'])) <span class="badge b-blue">+ مولّدة</span> @endif</div>
                <div class="stat-value">{{ number_format(collect($counts[$key] ?? [])->sum()) }}</div>
                <div class="stat-note">
                    @foreach ($difficulties as $level => $label)
                        {{ $label }} {{ $counts[$key][$level] ?? 0 }}@if (! $loop->last) · @endif
                    @endforeach
                </div>
            </button>
        @endforeach
    </div>

    @if ($showCategories)
        <form class="card" wire:submit="saveCategories" style="margin-bottom:16px">
            <div class="card-head">
                <h2>أسماء المجموعات</h2>
                @if ($categoriesOverridden)
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="resetCategories">رجوع للافتراضي</button>
                @endif
            </div>
            <div class="card-body grid grid-3">
                @foreach ($categoryLabels as $key => $cat)
                    <div class="row" wire:key="hcat-{{ $key }}" style="align-items:flex-start">
                        <input class="input" style="width:60px;text-align:center" wire:model="categoryLabels.{{ $key }}.emoji" maxlength="8">
                        <div class="field" style="flex:1">
                            <input class="input" wire:model="categoryLabels.{{ $key }}.label" maxlength="30">
                            <span class="hint mono">{{ $key }}</span>
                            @error("categoryLabels.$key.label") <span class="error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="card-foot"><button class="btn btn-primary" type="submit">حفظ الأسماء</button></div>
        </form>
    @endif

    <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="padding-bottom:0">
            <div class="toolbar">
                <input class="input" type="search" wire:model.live.debounce.400ms="search" placeholder="🔍 نص السؤال أو الجواب">
                <select class="select" wire:model.live="category">
                    <option value="">كل المجموعات</option>
                    @foreach ($categories as $key => $cat)
                        <option value="{{ $key }}">{{ $cat['label'] }}</option>
                    @endforeach
                </select>
                <select class="select" wire:model.live="difficulty">
                    <option value="">كل الصعوبات</option>
                    @foreach ($difficulties as $level => $label)
                        <option value="{{ $level }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($questions->isEmpty())
            <div class="empty"><div class="empty-emoji">❓</div>ما في أسئلة بهالفلتر.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>السؤال</th><th>الجواب</th><th>الخيارات الخاطئة</th><th>المجموعة</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($questions as $question)
                        <tr wire:key="q-{{ $question->id }}">
                            <td style="max-width:340px">{{ $question->prompt }}</td>
                            <td><span class="badge b-green">{{ $question->answer }}</span></td>
                            <td class="muted small">{{ implode(' · ', $question->distractors ?? []) }}</td>
                            <td class="small">
                                {{ $categories[$question->category]['label'] ?? $question->category }}
                                <div class="muted">{{ $difficulties[$question->difficulty] ?? $question->difficulty }}</div>
                            </td>
                            <td class="actions">
                                <button class="btn btn-sm" wire:click="edit({{ $question->id }})">تعديل</button>
                                <button class="btn btn-sm btn-soft-danger" wire:click="delete({{ $question->id }})" wire:confirm="حذف السؤال؟">حذف</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $questions->links() }}
        @endif
    </div>

    @include('livewire.admin.content.partials.import', [
        'formatHint' => 'ملف JSONL: سطر لكل سؤال بصيغة <code>{"c":"religion","d":"easy","q":"السؤال","a":"الجواب","w":["خطأ","خطأ","خطأ"],"e":"توضيح"}</code>. الأسئلة المكرّرة بتتجاهل تلقائياً.',
    ])

    @if ($editingId !== null)
        <div class="modal-backdrop" wire:click.self="$set('editingId', null)">
            <form class="modal" wire:submit="save">
                <div class="modal-head">
                    <h3>{{ $editingId ? 'تعديل سؤال' : 'سؤال جديد' }}</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('editingId', null)">✕</button>
                </div>
                <div class="modal-body form-grid">
                    <div class="field">
                        <label>المجموعة</label>
                        <select class="select" wire:model="form.category">
                            @foreach ($categories as $key => $cat)
                                <option value="{{ $key }}">{{ $cat['label'] }}</option>
                            @endforeach
                        </select>
                        @error('form.category') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label>الصعوبة</label>
                        <select class="select" wire:model="form.difficulty">
                            @foreach ($difficulties as $level => $label)
                                <option value="{{ $level }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field full">
                        <label>السؤال</label>
                        <textarea class="textarea" wire:model="form.prompt" maxlength="400" style="min-height:70px"></textarea>
                        @error('form.prompt') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field full">
                        <label>✅ الجواب الصحيح</label>
                        <input class="input" wire:model="form.answer" maxlength="200">
                        @error('form.answer') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    @foreach ($form['distractors'] ?? [] as $index => $option)
                        <div class="field" wire:key="d-{{ $index }}">
                            <label>❌ خيار خاطئ {{ $index + 1 }}</label>
                            <input class="input" wire:model="form.distractors.{{ $index }}" maxlength="200">
                        </div>
                    @endforeach
                    <div class="field full">
                        @error('form.distractors') <span class="error">{{ $message }}</span> @enderror
                        <button type="button" class="btn btn-sm" wire:click="addDistractor" style="align-self:flex-start">+ خيار خاطئ احتياطي</button>
                    </div>
                    <div class="field full">
                        <label>توضيح بعد الكشف (اختياري)</label>
                        <input class="input" wire:model="form.explanation" maxlength="400">
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
