<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // إيقاف حساب من لوحة الإدارة. توقيت لا مجرد علَم: نعرف متى أُوقف،
        // والسبب يُعرض لصاحبه حين يحاول الدخول.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable()->after('fcm_token');
            $table->string('ban_reason', 300)->nullable()->after('banned_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_at', 'ban_reason']);
        });
    }
};
