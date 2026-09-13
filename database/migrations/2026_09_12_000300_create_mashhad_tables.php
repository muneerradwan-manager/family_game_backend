<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // بنك مشاهد لعبة المشهد.
        //
        // في القاعدة لا في ملفات: البنك يكبر، وقراءة كل المشاهد لسحب واحد
        // إهدار. والمفتاح `key` فريد فيمنع دخول المشهد نفسه مرتين مهما تكرّر
        // الاستيراد.
        Schema::create('mashhad_scenes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('category', 20);
            $table->string('title', 200);
            $table->string('setup', 600);
            // [{n: الاسم, g: الهدف, b: هدف إضافي, s: سرّ, d: الصعوبة}]
            $table->json('roles');
            $table->json('events'); // [{t: النص, scope: all|one}]
            $table->string('source', 60)->nullable();
            $table->timestamps();
            $table->index('category');
        });

        // أرشيف مشاهد الجلسة — يُكتب عند نهاية كل مشهد من الحالة الحيّة.
        Schema::create('mashhad_rounds', function (Blueprint $table) {
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('scene_no');
            $table->string('scene_key', 60);
            $table->string('title', 200);
            $table->json('cast');       // {userId: {role, goal, bonus, secret, difficulty}}
            $table->json('transcript'); // [{userId, username, text, at}]
            $table->json('events');     // [{text, scope, userId, at}]
            $table->json('claims');     // {userId: {main, bonus, event, verdicts}}
            $table->json('scores');     // {userId: {main, bonus, hard, event, penalties, total}}
            $table->timestamp('created_at')->nullable();
            $table->primary(['game_id', 'scene_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mashhad_rounds');
        Schema::dropIfExists('mashhad_scenes');
    }
};
