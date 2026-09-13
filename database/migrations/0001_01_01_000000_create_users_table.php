<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // الرقم بصيغة E.164 دائماً (+9627xxxxxxxx) — جاهز لتحقق SMS لاحقاً بلا تهجير بيانات.
            $table->string('phone', 20)->unique();
            $table->string('password_hash');
            $table->string('full_name', 80);
            // معرّف البحث والدعوات: [a-z0-9_]{3,20} فريد على مستوى التطبيق.
            $table->string('username', 20)->unique();
            $table->string('photo_url')->nullable();
            $table->string('avatar_id', 40)->nullable();
            $table->string('gender', 10);
            $table->string('recovery_email')->nullable();
            // للإشعارات فقط (دعوة/تنبيه) — ليست أداة تزامن.
            $table->string('fcm_token', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // نخزّن sha256 للتوكن لا التوكن نفسه.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('users');
    }
};
