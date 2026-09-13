<?php

namespace App\Admin;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * سجل تدقيق لكل ما يغيّره المشرف.
 *
 * لوحة تستطيع إيقاف حساب أو إنهاء جلسة أو تغيير نقاط لعبة يجب أن تجيب
 * لاحقاً عن «مين عمل هيك ومتى». السطر يُكتب لحظة الفعل لا بعدها.
 */
final class AdminAudit
{
    /**
     * @param  array<string, mixed>  $changes  {field: {from, to}} أو أي تفصيل مفيد
     */
    public static function record(
        string $action,
        string $summary,
        ?Model $subject = null,
        array $changes = [],
    ): void {
        $admin = Auth::guard('admin')->user();

        AdminAuditLog::create([
            'admin_id' => $admin?->getKey(),
            'admin_email' => $admin?->email,
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'summary' => mb_substr($summary, 0, 300),
            'changes' => $changes === [] ? null : $changes,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * الفرق بين قيمتين كما يُخزَّن في السجل.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }
}
