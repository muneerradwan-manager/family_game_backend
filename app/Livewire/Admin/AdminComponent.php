<?php

namespace App\Livewire\Admin;

use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * أساس كل شاشات لوحة الإدارة.
 *
 * الحماية هنا لا في المسار وحده: مكوّن Livewire يتلقّى طلبات تحديث على
 * مسار Livewire نفسه لا على مسار الصفحة، فالفحص يجب أن يُعاد مع كل تحديث.
 * صفحة فُتحت ثم انتهت جلستها لا يجوز أن تبقى تنفّذ أزرارها.
 */
abstract class AdminComponent extends Component
{
    public function boot(): void
    {
        abort_unless(Auth::guard('admin')->check(), 403);
    }

    protected function admin(): Admin
    {
        /** @var Admin */
        return Auth::guard('admin')->user();
    }

    /** رسالة عابرة أعلى الشاشة. */
    protected function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('notify', message: $message, type: $type);
    }

    public function paginationView(): string
    {
        return 'admin.partials.pagination';
    }

    /**
     * رسائل التحقق بالعربي.
     *
     * التطبيق بلا ملفات ترجمة (رسائل الـ API مكتوبة بالعربي في مكانها)، فبدل
     * أن يرى المشرف «The form.name field is required» نعطي كل قاعدة رسالتها.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => 'حقل «:attribute» مطلوب.',
            'string' => 'حقل «:attribute» لازم يكون نص.',
            'integer' => 'حقل «:attribute» لازم يكون رقم صحيح.',
            'numeric' => 'حقل «:attribute» لازم يكون رقم.',
            'boolean' => 'قيمة «:attribute» غير صالحة.',
            'array' => 'قيمة «:attribute» غير صالحة.',
            'email' => 'حقل «:attribute» لازم يكون إيميل صحيح.',
            'url' => 'حقل «:attribute» لازم يكون رابط صحيح.',
            'max' => 'حقل «:attribute» أكبر من المسموح (:max).',
            'min' => 'حقل «:attribute» أقل من المسموح (:min).',
            'between' => 'حقل «:attribute» لازم يكون بين :min و :max.',
            'unique' => 'قيمة «:attribute» مستخدمة من قبل.',
            'regex' => 'صيغة «:attribute» غير صحيحة.',
            'in' => 'قيمة «:attribute» غير مسموحة.',
            'confirmed' => 'تأكيد «:attribute» غير مطابق.',
            'date' => 'حقل «:attribute» لازم يكون تاريخ.',
            'after' => 'حقل «:attribute» لازم يكون بعد :date.',
            'distinct' => 'قيمة «:attribute» مكرّرة.',
            'file' => 'لازم ترفع ملف.',
            'current_password' => 'كلمة السر الحالية غير صحيحة.',
        ];
    }
}
