<div>
    <div class="page-head">
        <div>
            <h1>الألعاب</h1>
            <p>شو بيظهر بالتطبيق، وبأي اسم، وكل أرقام كل لعبة.</p>
        </div>
    </div>

    <div class="grid grid-2">
        @foreach ($games as $game)
            <div class="card" wire:key="game-{{ $game['type'] }}">
                <div class="card-body stack" style="gap:10px">
                    <div class="row">
                        <span style="font-size:34px">{{ $game['presentation']['icon'] }}</span>
                        <div style="flex:1">
                            <h2 style="margin:0;font-size:18px">{{ $game['presentation']['name'] }}</h2>
                            <span class="muted small mono">{{ $game['type'] }}</span>
                        </div>
                        <span class="badge {{ $game['enabled'] ? 'b-green' : 'b-red' }}">{{ $game['enabled'] ? 'متاحة' : 'موقوفة' }}</span>
                        @if ($game['customized']) <span class="badge b-amber">معدّلة</span> @endif
                    </div>
                    <p class="muted" style="margin:0">{{ $game['presentation']['description'] }}</p>
                    <div class="row small muted">
                        <span>👥 {{ $game['players'] }} لاعب</span>
                        <span>· 🎮 {{ $game['total'] }} جلسة</span>
                        <span>· 🟢 {{ $game['live'] }} شغّالة</span>
                    </div>
                </div>
                <div class="card-foot row">
                    <a class="btn btn-sm btn-primary" href="{{ route('admin.games.settings', $game['type']) }}" wire:navigate>⚙️ الإعدادات والأرقام</a>
                    @if ($game['contentRoute'])
                        <a class="btn btn-sm" href="{{ route($game['contentRoute']) }}" wire:navigate>📚 المحتوى</a>
                    @endif
                    <button class="btn btn-sm" wire:click="edit('{{ $game['type'] }}')">✏️ الاسم والوصف</button>
                    <span class="spacer"></span>
                    <button class="btn btn-sm {{ $game['enabled'] ? 'btn-soft-danger' : '' }}" wire:click="toggle('{{ $game['type'] }}')"
                            wire:confirm="{{ $game['enabled'] ? 'إيقاف اللعبة؟ ما حدا بيقدر يفتح غرفة جديدة إلها.' : 'إرجاع اللعبة للتطبيق؟' }}">
                        {{ $game['enabled'] ? 'إيقاف' : 'تفعيل' }}
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    @if ($editingType)
        <div class="modal-backdrop" wire:click.self="$set('editingType', null)">
            <form class="modal" wire:submit="save">
                <div class="modal-head">
                    <h3>كيف بتظهر اللعبة بالتطبيق</h3>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('editingType', null)">✕</button>
                </div>
                <div class="modal-body form-grid">
                    <div class="field">
                        <label>الأيقونة (إيموجي)</label>
                        <input class="input" wire:model="form.icon" maxlength="8" style="font-size:22px;text-align:center">
                        @error('form.icon') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label>الاسم</label>
                        <input class="input" wire:model="form.name" maxlength="40">
                        @error('form.name') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field full">
                        <label>الوصف</label>
                        <textarea class="textarea" wire:model="form.description" maxlength="300"></textarea>
                        @error('form.description') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <p class="hint full" style="margin:0">لترجع للاسم الأصلي، اكتبه متل ما كان — التعديل بينحذف تلقائياً.</p>
                </div>
                <div class="modal-foot">
                    <button class="btn btn-primary" type="submit">حفظ</button>
                    <button class="btn" type="button" wire:click="$set('editingType', null)">إلغاء</button>
                </div>
            </form>
        </div>
    @endif
</div>
