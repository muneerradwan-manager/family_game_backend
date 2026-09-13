<div>
    <div class="page-head">
        <div>
            <h1>🕵️ كلمات الجاسوس</h1>
            <p>كل مجموعة كلماتها متقاربة عمداً — تخمين الجاسوس الأخير بيسحب خياراته من نفس المجموعة.</p>
        </div>
        <div class="page-actions">
            <a class="btn" href="{{ route('admin.games.settings', 'spy') }}" wire:navigate>⚙️ أرقام اللعبة</a>
        </div>
    </div>

    <form wire:submit="save" class="stack">
        <div class="card">
            @include('livewire.admin.content.partials.section-head', ['title' => 'المجموعات ('.count($categories).')', 'key' => 'spy.categories', 'overridden' => $overridden])
            <div class="card-body">
                <p class="muted small" style="margin-top:0">كلمة بكل سطر (أو مفصولة بفاصلة). على الأقل {{ $minWords }} كلمات لكل مجموعة، ويفضّل 20 حتى ما تتكرر.</p>
                @error('categories') <div class="error">{{ $message }}</div> @enderror

                <div class="grid grid-2">
                    @foreach ($categories as $index => $category)
                        <div class="repeater-item" wire:key="spy-cat-{{ $index }}" style="margin:0">
                            <div class="row" style="align-items:flex-start">
                                <div class="field" style="width:70px">
                                    <label>إيموجي</label>
                                    <input class="input" wire:model="categories.{{ $index }}.emoji" maxlength="8" style="text-align:center">
                                </div>
                                <div class="field" style="flex:1">
                                    <label>الاسم</label>
                                    <input class="input" wire:model="categories.{{ $index }}.label" maxlength="30">
                                    @error("categories.$index.label") <span class="error">{{ $message }}</span> @enderror
                                </div>
                                <div class="field" style="flex:1">
                                    <label>المفتاح</label>
                                    <input class="input ltr mono" wire:model="categories.{{ $index }}.key" maxlength="30">
                                    @error("categories.$index.key") <span class="error">{{ $message }}</span> @enderror
                                </div>
                            </div>
                            <div class="field" style="margin-top:10px">
                                <label>الكلمات ({{ count(preg_split('/[\r\n,،]+/u', $category['words'], -1, PREG_SPLIT_NO_EMPTY) ?: []) }})</label>
                                <textarea class="textarea" wire:model.blur="categories.{{ $index }}.words" style="min-height:160px"></textarea>
                                @error("categories.$index.words") <span class="error">{{ $message }}</span> @enderror
                            </div>
                            <div class="row" style="margin-top:8px">
                                <span class="spacer"></span>
                                <button type="button" class="btn btn-sm btn-soft-danger" wire:click="remove({{ $index }})"
                                        wire:confirm="حذف المجموعة؟ (ما بتنحذف فعلياً إلا لما تحفظ)">حذف المجموعة</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="card-foot row">
                <button class="btn btn-primary" type="submit">حفظ كل المجموعات</button>
                <button class="btn" type="button" wire:click="add">+ مجموعة</button>
                <span wire:loading wire:target="save" class="muted small">عم يحفظ…</span>
            </div>
        </div>
    </form>
</div>
