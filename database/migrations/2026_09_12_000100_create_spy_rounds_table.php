<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // أرشيف جولات وحدة "لعبة الجاسوس" — يُكتب عند نهاية كل جولة من الحالة الحيّة.
        //
        // لاحظ ما ليس هنا: الكلمة والجاسوس. كلاهما خاصية باللعبة كلها لا
        // بالجولة، ومكانهما games.result بعد انتهاء الجلسة — قبلها لا يُكتبان
        // في القاعدة أصلاً.
        Schema::create('spy_rounds', function (Blueprint $table) {
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round_no');
            // [{askerId, targetId, question, answer, skipped, unanswered}]
            $table->json('turns');
            $table->json('votes'); // {voterId: suspectId}
            $table->uuid('ejected_user_id')->nullable();
            $table->boolean('tie')->default(false); // تعادل = ما خرج حدا
            $table->timestamp('created_at')->nullable();
            $table->primary(['game_id', 'round_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spy_rounds');
    }
};
