<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // طبقة المنصّة: مشتركة بين كل الألعاب. اللعبة الجديدة تضيف جداولها الخاصة
        // وتشارك games/game_players نفسها — هذا هو "عقد الوحدة" على مستوى البيانات.
        Schema::create('games', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->string('game_type', 30); // "harf" ← مفتاح تعدد الألعاب
            $table->string('status', 15);    // lobby | playing | finished | abandoned
            $table->json('config');          // شروط اللعبة حسب نوعها
            $table->foreignUuid('started_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // { winnerId, finalScores, endedEarly } — سجل القناة و"بطولة العيلة" يقرآن منه مباشرة.
            $table->json('result')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'status']);
            $table->index(['channel_id', 'finished_at']);
        });

        Schema::create('game_players', function (Blueprint $table) {
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('join_order');
            $table->boolean('is_spectator')->default(false);
            $table->integer('final_score')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->primary(['game_id', 'user_id']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
        Schema::dropIfExists('games');
    }
};
