<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // بنك أسئلة لعبة الهدف.
        //
        // في القاعدة لا في ملفات: الهدف عشرة آلاف سؤال، وقراءة ملف بهذا الحجم
        // في كل جولة لسحب سؤال واحد إهدار. والـ fingerprint يمنع دخول السؤال
        // نفسه مرتين مهما تكرّر الاستيراد.
        Schema::create('hadaf_questions', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20);
            $table->string('difficulty', 10);
            $table->string('prompt', 400);
            $table->string('answer', 200);
            $table->json('distractors');            // ["خيار", "خيار", "خيار"]
            $table->string('explanation', 400)->nullable();
            $table->string('source', 60)->nullable(); // من أين استُورد
            $table->char('fingerprint', 40)->unique();
            $table->timestamps();
            $table->index(['category', 'difficulty']);
        });

        // أرشيف جولات لعبة الهدف — يُكتب عند نهاية كل جولة من الحالة الحيّة.
        Schema::create('hadaf_rounds', function (Blueprint $table) {
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round_no');
            $table->string('category', 20);
            $table->string('difficulty', 10);
            $table->string('prompt', 400);
            $table->string('answer', 200);
            $table->json('answers'); // {userId: {choice, correct, ms, risk}}
            $table->json('scores');  // {userId: {base, speed, streak, risk, total}}
            $table->timestamp('created_at')->nullable();
            $table->primary(['game_id', 'round_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hadaf_rounds');
        Schema::dropIfExists('hadaf_questions');
    }
};
