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
            // No games table until M6; kept as a plain nullable FK column
            // (not constrained()) so it doesn't reference a nonexistent table.
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
