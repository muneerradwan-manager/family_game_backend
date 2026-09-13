<div>
    <div class="page-head">
        <div>
            <h1>🔠 محتوى لعبة الحروف</h1>
            <p>الحروف اللي بتنسحب، أسماء الأعمدة، وخيارات العمود السادس. التعديل بيسري من أول جولة جاية.</p>
        </div>
        <div class="page-actions">
            <a class="btn" href="{{ route('admin.games.settings', 'harf') }}" wire:navigate>⚙️ أرقام اللعبة</a>
        </div>
    </div>

    <div class="stack">
        <form class="card" wire:submit="saveLetters">
            @include('livewire.admin.content.partials.section-head', ['title' => 'بنك الحروف', 'key' => 'harf.letters', 'overridden' => $overridden['harf.letters']])
            <div class="card-body stack">
                <div class="field">
                    <label>الحروف مفصولة بمسافة</label>
                    <textarea class="textarea" wire:model="letters" style="font-size:22px;letter-spacing:4px;min-height:80px"></textarea>
                    <span class="hint">الحروف الصعبة (ث ذ ظ ض) مستبعدة افتراضياً — ضيفها إذا بدك تحدّي أكبر.</span>
                    @error('letters') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="card-foot"><button class="btn btn-primary" type="submit">حفظ الحروف</button></div>
        </form>

        <div class="grid grid-2">
            <form class="card" wire:submit="saveColumns">
                @include('livewire.admin.content.partials.section-head', ['title' => 'أسماء الأعمدة الخمسة', 'key' => 'harf.columns', 'overridden' => $overridden['harf.columns']])
                <div class="card-body stack">
                    @foreach ($columns as $key => $label)
                        <div class="field" wire:key="column-{{ $key }}">
                            <label class="mono">{{ $key }}</label>
                            <input class="input" wire:model="columns.{{ $key }}" maxlength="30">
                            @error("columns.$key") <span class="error">{{ $message }}</span> @enderror
                        </div>
                    @endforeach
                </div>
                <div class="card-foot"><button class="btn btn-primary" type="submit">حفظ الأسماء</button></div>
            </form>

            <form class="card" wire:submit="saveFlexible">
                @include('livewire.admin.content.partials.section-head', ['title' => 'أعمدة الوضع المرن', 'key' => 'harf.flexible_columns', 'overridden' => $overridden['harf.flexible_columns']])
                <div class="card-body stack">
                    <p class="muted small" style="margin:0">الوضع المرن للصغار وكبار السن: أعمدة أقل ووقت أطول.</p>
                    @foreach ($columns as $key => $label)
                        <label class="check" wire:key="flex-{{ $key }}">
                            <input type="checkbox" value="{{ $key }}" wire:model="flexibleColumns">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                    @error('flexibleColumns') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="card-foot"><button class="btn btn-primary" type="submit">حفظ</button></div>
            </form>
        </div>

        <form class="card" wire:submit="saveSixth">
            @include('livewire.admin.content.partials.section-head', ['title' => 'خيارات العمود السادس', 'key' => 'harf.sixth_columns', 'overridden' => $overridden['harf.sixth_columns']])
            <div class="card-body">
                @error('sixthColumns') <div class="error" style="margin-bottom:8px">{{ $message }}</div> @enderror
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>الاسم</th><th>المفتاح</th><th>المستوى</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($sixthColumns as $index => $column)
                            <tr wire:key="sixth-{{ $index }}">
                                <td>
                                    <input class="input" wire:model="sixthColumns.{{ $index }}.label" maxlength="30">
                                    @error("sixthColumns.$index.label") <span class="error">{{ $message }}</span> @enderror
                                </td>
                                <td>
                                    <input class="input ltr mono" wire:model="sixthColumns.{{ $index }}.key" maxlength="30">
                                    @error("sixthColumns.$index.key") <span class="error">{{ $message }}</span> @enderror
                                </td>
                                <td>
                                    <select class="select" wire:model="sixthColumns.{{ $index }}.level">
                                        @foreach ($levels as $value => $name)
                                            <option value="{{ $value }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="actions">
                                    <button type="button" class="btn btn-sm btn-soft-danger" wire:click="removeSixth({{ $index }})">حذف</button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-foot row">
                <button class="btn btn-primary" type="submit">حفظ الخيارات</button>
                <button class="btn" type="button" wire:click="addSixth">+ عمود</button>
            </div>
        </form>
    </div>
</div>
