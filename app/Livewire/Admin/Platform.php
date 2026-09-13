<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\EditsSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * مفاتيح التطبيق العامة: الصيانة، التسجيل، وأدنى نسخة.
 */
#[Layout('admin.layout')]
#[Title('مفاتيح التطبيق')]
class Platform extends AdminComponent
{
    use EditsSettings;

    public bool $maintenanceEnabled = false;

    public string $maintenanceMessage = '';

    public bool $registrationEnabled = true;

    public string $registrationMessage = '';

    public string $minVersion = '';

    public string $minVersionMessage = '';

    public string $storeUrl = '';

    public function mount(): void
    {
        $this->loadValues();
    }

    private function loadValues(): void
    {
        $this->maintenanceEnabled = (bool) config('platform.maintenance.enabled');
        $this->maintenanceMessage = (string) config('platform.maintenance.message');
        $this->registrationEnabled = (bool) config('platform.registration.enabled');
        $this->registrationMessage = (string) config('platform.registration.message');
        $this->minVersion = (string) config('platform.min_app_version.version');
        $this->minVersionMessage = (string) config('platform.min_app_version.message');
        $this->storeUrl = (string) config('platform.min_app_version.store_url');
    }

    public function save(): void
    {
        $this->validate([
            'maintenanceMessage' => ['required', 'string', 'max:300'],
            'registrationMessage' => ['required', 'string', 'max:300'],
            'minVersion' => ['nullable', 'regex:/^\d+(\.\d+){0,3}$/'],
            'minVersionMessage' => ['required', 'string', 'max:300'],
            'storeUrl' => ['nullable', 'url', 'max:500'],
        ], [], [
            'maintenanceMessage' => 'رسالة الصيانة',
            'registrationMessage' => 'رسالة إغلاق التسجيل',
            'minVersion' => 'أدنى نسخة',
            'minVersionMessage' => 'رسالة التحديث',
            'storeUrl' => 'رابط المتجر',
        ]);

        $changed = collect([
            $this->saveSetting('platform.maintenance.enabled', $this->maintenanceEnabled, 'وضع الصيانة'),
            $this->saveSetting('platform.maintenance.message', trim($this->maintenanceMessage), 'رسالة الصيانة'),
            $this->saveSetting('platform.registration.enabled', $this->registrationEnabled, 'فتح التسجيل'),
            $this->saveSetting('platform.registration.message', trim($this->registrationMessage), 'رسالة إغلاق التسجيل'),
            $this->saveSetting('platform.min_app_version.version', trim($this->minVersion) === '' ? null : trim($this->minVersion), 'أدنى نسخة للتطبيق'),
            $this->saveSetting('platform.min_app_version.message', trim($this->minVersionMessage), 'رسالة التحديث الإجباري'),
            $this->saveSetting('platform.min_app_version.store_url', trim($this->storeUrl) === '' ? null : trim($this->storeUrl), 'رابط المتجر'),
        ])->contains(true);

        $this->notify($changed ? 'انحفظت المفاتيح — سارية فوراً.' : 'ما في تغيير.', $changed ? 'success' : 'warning');
    }

    public function resetAll(): void
    {
        foreach ([
            'platform.maintenance.enabled', 'platform.maintenance.message',
            'platform.registration.enabled', 'platform.registration.message',
            'platform.min_app_version.version', 'platform.min_app_version.message',
            'platform.min_app_version.store_url',
        ] as $key) {
            $this->resetSetting($key, $key);
        }

        $this->loadValues();
        $this->notify('رجعت المفاتيح للافتراضي.');
    }

    public function render()
    {
        return view('livewire.admin.platform');
    }
}
