<div>
    <div class="page-head">
        <div>
            <h1>حسابي</h1>
            <p class="mono">{{ auth('admin')->user()->email }}</p>
        </div>
    </div>

    <div class="grid grid-2">
        <form class="card" wire:submit="saveName">
            <div class="card-head"><h2>الاسم</h2></div>
            <div class="card-body">
                <div class="field">
                    <label>الاسم الظاهر</label>
                    <input class="input" wire:model="name" maxlength="80">
                    @error('name') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="card-foot"><button class="btn btn-primary" type="submit">حفظ</button></div>
        </form>

        <form class="card" wire:submit="savePassword">
            <div class="card-head"><h2>كلمة السر</h2></div>
            <div class="card-body stack">
                <div class="field">
                    <label>الحالية</label>
                    <input class="input ltr" type="password" wire:model="currentPassword" autocomplete="current-password">
                    @error('currentPassword') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label>الجديدة</label>
                    <input class="input ltr" type="password" wire:model="password" autocomplete="new-password">
                    <span class="hint">10 أحرف على الأقل.</span>
                    @error('password') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label>تأكيد الجديدة</label>
                    <input class="input ltr" type="password" wire:model="passwordConfirmation" autocomplete="new-password">
                </div>
            </div>
            <div class="card-foot"><button class="btn btn-primary" type="submit">تغيير كلمة السر</button></div>
        </form>
    </div>
</div>
