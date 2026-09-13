<div>
    <div class="page-head">
        <div>
            <h1>مفاتيح التطبيق</h1>
            <p>تسري على كل الأجهزة فوراً، بلا تحديث للتطبيق.</p>
        </div>
        <div class="page-actions">
            <button class="btn" wire:click="resetAll" wire:confirm="إرجاع كل المفاتيح للافتراضي؟">رجوع للافتراضي</button>
        </div>
    </div>

    <form wire:submit="save" class="stack">
        <div class="card">
            <div class="card-head">
                <h2>🚧 وضع الصيانة</h2>
                <label class="check"><input type="checkbox" wire:model.live="maintenanceEnabled"> <span>مفعّل</span></label>
            </div>
            <div class="card-body stack">
                @if ($maintenanceEnabled)
                    <div class="callout callout-danger">
                        لما تحفظ، التطبيق بيتسكّر بوجه الكل: كل الطلبات بترجع شاشة الصيانة، والألعاب الشغّالة بتوقف عن استقبال الحركات.
                    </div>
                @endif
                <div class="field">
                    <label>الرسالة اللي بتظهر للمستخدمين</label>
                    <textarea class="textarea" wire:model="maintenanceMessage" maxlength="300"></textarea>
                    @error('maintenanceMessage') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>📝 التسجيل</h2>
                <label class="check"><input type="checkbox" wire:model.live="registrationEnabled"> <span>مفتوح</span></label>
            </div>
            <div class="card-body">
                <div class="field">
                    <label>الرسالة لما يكون مسكّر</label>
                    <textarea class="textarea" wire:model="registrationMessage" maxlength="300"></textarea>
                    <span class="hint">اللي عندهم حسابات بيضلّوا يقدروا يدخلوا عادي.</span>
                    @error('registrationMessage') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>📲 التحديث الإجباري</h2></div>
            <div class="card-body form-grid">
                <div class="field">
                    <label>أدنى نسخة مسموحة</label>
                    <input class="input ltr" wire:model="minVersion" placeholder="مثلاً 1.2.0 — فاضي = بلا حد">
                    <span class="hint">أي نسخة أقدم بتشوف شاشة «حدّث التطبيق» وما بتقدر تكمل.</span>
                    @error('minVersion') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label>رابط المتجر</label>
                    <input class="input ltr" wire:model="storeUrl" placeholder="https://play.google.com/store/apps/details?id=…">
                    @error('storeUrl') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field full">
                    <label>رسالة التحديث</label>
                    <textarea class="textarea" wire:model="minVersionMessage" maxlength="300"></textarea>
                    @error('minVersionMessage') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="row">
            <button class="btn btn-primary" type="submit">حفظ المفاتيح</button>
            <span wire:loading wire:target="save" class="muted small">عم يحفظ…</span>
        </div>
    </form>
</div>
