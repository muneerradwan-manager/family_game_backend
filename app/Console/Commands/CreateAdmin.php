<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * إنشاء مشرف أو إعادة تعيين كلمة سرّه.
 *
 * من سطر الأوامر لا من ملف seeder: كلمة السر لا يجوز أن تُكتب في كود
 * المستودع. تُخزَّن هنا كـ hash فقط، وإعادة التشغيل بنفس الإيميل تحدّث
 * الحساب بدل أن تكرّره — وهكذا يُستعاد الدخول لو نُسيت كلمة السر.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
                            {--email= : إيميل المشرف}
                            {--name= : الاسم المعروض}
                            {--password= : كلمة السر (تُطلب بشكل مخفي إن لم تُمرَّر)}
                            {--super : مشرف أعلى يدير بقية المشرفين}';

    protected $description = 'ينشئ مشرفاً للوحة الإدارة أو يعيد تعيين كلمة سرّه.';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('الإيميل');
        $name = $this->option('name') ?: ($this->ask('الاسم', 'المشرف') ?? 'المشرف');
        $password = $this->option('password') ?: $this->secret('كلمة السر (8 أحرف على الأقل)');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:80'],
                'password' => ['required', 'string', 'min:8', 'max:72'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $existing = Admin::where('email', $email)->first();

        $admin = Admin::updateOrCreate(
            ['email' => strtolower($email)],
            [
                'name' => $name,
                'password' => $password,
                // المشرف الأعلى لا يُسحب منه دوره بإعادة تعيين كلمة سرّه.
                'is_super' => $this->option('super') || ($existing?->is_super ?? false),
            ],
        );

        $this->info($existing
            ? "حُدّث المشرف {$admin->email}."
            : "أُنشئ المشرف {$admin->email}.");

        if ($admin->is_super) {
            $this->line('  صلاحية: مشرف أعلى');
        }

        return self::SUCCESS;
    }
}
