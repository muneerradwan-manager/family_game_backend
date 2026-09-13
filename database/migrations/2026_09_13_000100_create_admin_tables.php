<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // مشرفو لوحة الإدارة — جدول مستقل عن مستخدمي التطبيق عمداً: الدخول
        // هنا بالإيميل وجلسة متصفح، وهناك برقم الهاتف وJWT. خلطهما يعني أن
        // ثغرة في أحد الطريقين تفتح الآخر.
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('email')->unique();
            $table->string('password');
            // المشرف الأعلى وحده يدير المشرفين الآخرين.
            $table->boolean('is_super')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // تعديلات المشرف فوق قيم config/*.php.
        //
        // الملفات تبقى المرجع الافتراضي، وهذا الجدول يحمل ما غيّره المشرف
        // فقط. حذف الصف = العودة للافتراضي. والمفتاح مسار config نفسه
        // (harf.phases.grace) فتقرؤه الألعاب بلا أي تعديل عليها.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 150)->primary();
            $table->json('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // سجل تدقيق: من غيّر ماذا ومتى. الإيميل يُنسخ مع كل سطر حتى يبقى
        // السجل مقروءاً بعد حذف المشرف.
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('admin_email')->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('summary', 300);
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        // ثيمات التطبيق — كانت مكتوبة داخل كود Flutter، وصارت تُجلب من هنا.
        Schema::create('app_themes', function (Blueprint $table) {
            $table->id();
            // المعرّف الذي يحفظه الجهاز — لا يتغيّر مع تغيّر الاسم المعروض.
            $table->string('key', 40)->unique();
            $table->string('name', 40);
            $table->string('tagline', 80)->nullable();
            $table->boolean('is_dark')->default(false);
            $table->json('colors');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->string('level', 10)->default('info'); // info | success | warning | danger
            $table->string('audience', 10)->default('all'); // all | channel
            $table->foreignUuid('channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_dismissible')->default(true);
            $table->timestamp('push_sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'audience']);
        });

        $this->seedBuiltInThemes();
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('app_themes');
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('admins');
    }

    /**
     * الثيمات الستة التي كانت داخل التطبيق — بمعرّفاتها نفسها.
     *
     * المعرّفات لا تتغيّر حتى لا يفقد أحد اختياره المحفوظ على جهازه حين
     * يبدأ التطبيق بجلبها من السيرفر.
     */
    private function seedBuiltInThemes(): void
    {
        $themes = [
            ['sea_breeze', 'نسيم البحر', 'أزرق هادي وبارد', false, [
                '#1B7FA8', '#FFFFFF', '#4FC3C7', '#FF7043', '#EFF7FA', '#FFFFFF',
                '#DCEEF5', '#0D3B4C', '#5C8195', '#B6D8E5', '#1B7FA8', '#4FC3C7',
            ]],
            ['summer_sunset', 'غروب الصيف', 'برتقالي دافي', false, [
                '#E0603A', '#FFFFFF', '#F2A65A', '#D81B60', '#FFF6EF', '#FFFFFF',
                '#FFE6D6', '#52251A', '#9A6A56', '#F3CBB4', '#E0603A', '#F2A65A',
            ]],
            ['mountains', 'الجبال', 'أخضر وحجري', false, [
                '#2F6B4F', '#FFFFFF', '#7FA88C', '#C7622F', '#F1F5F0', '#FFFFFF',
                '#DFE9DF', '#1E3528', '#63796B', '#C3D5C7', '#2F6B4F', '#7FA88C',
            ]],
            ['desert', 'الصحراء', 'رملي وذهبي', false, [
                '#B07A2E', '#FFFFFF', '#D9A441', '#8C4A2F', '#FBF5E9', '#FFFDF8',
                '#F2E4C9', '#4A3517', '#8A7351', '#E2CFA8', '#B07A2E', '#D9A441',
            ]],
            ['night', 'سهرة الليل', 'بنفسجي غامق', true, [
                '#8B7CF6', '#14102B', '#52D1DC', '#FF6B8A', '#14122A', '#1F1C3B',
                '#2A2650', '#EDEAFF', '#9A93C7', '#3A3568', '#6D5BD0', '#52D1DC',
            ]],
            ['orchard', 'البستان', 'أخضر فاتح ومنعش', false, [
                '#4C8C2B', '#FFFFFF', '#9CCC65', '#E91E63', '#F4FAEE', '#FFFFFF',
                '#E3F2D9', '#233B14', '#6B8258', '#CBE3B8', '#4C8C2B', '#9CCC65',
            ]],
        ];

        $keys = [
            'primary', 'onPrimary', 'secondary', 'accent', 'background', 'surface',
            'surfaceAlt', 'textPrimary', 'textMuted', 'outline', 'gradientStart', 'gradientEnd',
        ];

        foreach ($themes as $index => [$key, $name, $tagline, $isDark, $colors]) {
            DB::table('app_themes')->insert([
                'key' => $key,
                'name' => $name,
                'tagline' => $tagline,
                'is_dark' => $isDark,
                'colors' => json_encode(array_combine($keys, $colors)),
                'is_active' => true,
                'is_default' => $index === 0,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
