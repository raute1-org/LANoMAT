<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // Constrained in a later migration (2026_07_16_200100), once the
            // games table exists (M6 Task 1) — kept as a plain nullable FK
            // column here so this migration doesn't reference it too early.
            $table->foreignId('game_id')->nullable();
            $table->string('name');
            $table->string('format');
            $table->string('status')->default('draft');
            $table->unsignedInteger('team_size');
            $table->unsignedInteger('max_entries')->nullable();
            $table->text('rules')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('checkin_opens_at')->nullable();
            $table->timestamp('checkin_closes_at')->nullable();
            $table->jsonb('settings')->default('{}');
            // FK added in a later migration, once tournament_entries exists.
            $table->unsignedBigInteger('winner_entry_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
