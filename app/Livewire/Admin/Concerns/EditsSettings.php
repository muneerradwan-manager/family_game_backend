<?php

namespace App\Livewire\Admin\Concerns;

use App\Admin\AdminAudit;
use App\Settings\SettingsStore;

/**
 * حفظ إعداد من اللوحة مع سجل التدقيق.
 *
 * قيمة تساوي الافتراضي تُحذف بدل أن تُحفظ: لو حُفظت لتجمّدت، فتعديلٌ لاحق
 * على ملف config (من المطوّر) لا يصل لأن الإعداد «معدّل» بالقيمة القديمة.
 */
trait EditsSettings
{
    protected function settings(): SettingsStore
    {
        return app(SettingsStore::class);
    }

    /** @return bool هل تغيّر شيء فعلاً */
    protected function saveSetting(string $key, mixed $value, string $label): bool
    {
        $store = $this->settings();

        if ($store->get($key) === $value && ($store->isOverridden($key) || $value === $store->default($key))) {
            return false;
        }

        $previous = $value === $store->default($key)
            ? $store->forget($key)
            : $store->put($key, $value, $this->admin()->id);

        AdminAudit::record('setting.updated', "عدّل «{$label}»", null, [
            'key' => $key,
            'from' => $previous,
            'to' => $value,
        ]);

        return true;
    }

    protected function resetSetting(string $key, string $label): bool
    {
        $store = $this->settings();

        if (! $store->isOverridden($key)) {
            return false;
        }

        $previous = $store->forget($key);

        AdminAudit::record('setting.reset', "أرجع «{$label}» للافتراضي", null, [
            'key' => $key,
            'from' => $previous,
            'to' => $store->get($key),
        ]);

        return true;
    }
}
