<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // أرشيف جولات وحدة "لعبة الحروف" — يُكتب عند نهاية كل جولة من الحالة الحيّة.
        Schema::create('harf_rounds', function (Blueprint $table) {
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round_no');
            $table->uuid('drawer_user_id');
            $table->string('letter', 1);
            $table->uuid('stop_by')->nullable();
            $table->json('answers');    // {userId: {name, animal, ...}}
            $table->json('objections'); // [{target, column, by, coObjectors, votes, verdict}]
            $table->json('scores');     // {userId: {perColumn, stopBonus, penalties, total}}
            $table->timestamp('created_at')->nullable();
            $table->primary(['game_id', 'round_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harf_rounds');
    }
};
