<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 60);
            $table->string('photo_url')->nullable();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->char('invite_code', 6)->unique();
            // مفتاح قاعدة "لعبة نشطة واحدة لكل قناة" — يُحدَّث داخل transaction.
            // بلا foreign key: الجدولان يشيران لبعضهما، وقيد دائري يعقّد الحذف.
            $table->uuid('active_game_id')->nullable();
            $table->timestamps();
        });

        Schema::create('channel_members', function (Blueprint $table) {
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member'); // owner | member
            $table->timestamp('joined_at');
            $table->primary(['channel_id', 'user_id']);
            $table->index(['user_id', 'joined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_members');
        Schema::dropIfExists('channels');
    }
};
