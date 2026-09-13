<div>
    <div class="page-head">
        <div>
            <h1>⚙️ إعدادات {{ $gameName }}</h1>
            <p>بتسري فوراً — حتى الجلسات الشغّالة بتاخدها من مرحلتها الجاية. الحقل الأصفر يعني غيّرته وما حفظته بعد.</p>
        </div>
        <div class="page-actions">
            <a class="btn" href="{{ route('admin.games.index') }}" wire:navigate>← الألعاب</a>
            <button class="btn" wire:click="resetAll" wire:confirm="إرجاع كل أرقام {{ $gameName }} للافتراضي؟">رجوع الكل للافتراضي</button>
        </div>
    </div>

    <form wire:submit="save" class="stack">
        @foreach ($sections as $section)
            <div class="card" wire:key="section-{{ $loop->index }}">
                <div class="card-head">
                    <h2>{{ $section['title'] }}</h2>
                    @if (! empty($section['hint'])) <span class="muted small">{{ $section['hint'] }}</span> @endif
                </div>
                @foreach ($section['fields'] as $field)
                    @php($slot = \App\Livewire\Admin\Games\Settings::slot($field['key']))
                    <div class="setting-row" wire:key="field-{{ $slot }}"
                         x-data="{ initial: @js($values[$slot] ?? null) }"
                         :class="{ 'is-changed': JSON.stringify($wire.values['{{ $slot }}']) !== JSON.stringify(initial) }">
                        <div class="setting-meta">
                            <strong>{{ $field['label'] }}</strong>
                            @if (! empty($field['hint'])) <span class="hint">{{ $field['hint'] }}</span> @endif
                            <span class="hint">
                                الافتراضي:
                                <span class="mono">
                                    @if ($field['type'] === 'bool')
                                        {{ $defaults[$field['key']] ? 'مفعّل' : 'مطفأ' }}
                                    @elseif ($field['type'] === 'select')
                                        {{ $field['options'][$defaults[$field['key']]] ?? $defaults[$field['key']] }}
                                    @else
                                        {{ $defaults[$field['key']] }}
                                    @endif
                                </span>
                                @if ($overridden[$field['key']]) · <span class="badge b-amber">معدّل</span> @endif
                            </span>
                        </div>

                        <div class="field">
                            @switch($field['type'])
                                @case('bool')
                                    <label class="check"><input type="checkbox" wire:model="values.{{ $slot }}"> <span>مفعّل</span></label>
                                    @break
                                @case('select')
                                    <select class="select" wire:model="values.{{ $slot }}">
                                        @foreach ($field['options'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @break
                                @case('int_list')
                                    <input class="input ltr mono" wire:model="values.{{ $slot }}">
                                    @break
                                @default
                                    <input class="input ltr mono" type="number" min="{{ $field['min'] }}" max="{{ $field['max'] }}" wire:model="values.{{ $slot }}">
                            @endswitch
                            @error("values.$slot") <span class="error">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            @if ($overridden[$field['key']])
                                <button type="button" class="btn btn-sm btn-ghost" wire:click="resetField('{{ $field['key'] }}')">↺ افتراضي</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="card" style="position:sticky;bottom:12px;z-index:10">
            <div class="card-body row">
                <button class="btn btn-primary" type="submit">حفظ الإعدادات</button>
                <span wire:loading wire:target="save" class="muted small">عم يحفظ…</span>
                <span class="spacer"></span>
                <span class="muted small">كل تعديل بينكتب بسجل التدقيق.</span>
            </div>
        </div>
    </form>
</div>
