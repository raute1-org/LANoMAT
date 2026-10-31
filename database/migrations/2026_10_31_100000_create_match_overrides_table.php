<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail of orga decisions on a match (walkover, correction of a
        // confirmed result, dispute resolution): who, when, why, and the
        // state that was replaced.
        Schema::create('match_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_status');
            $table->unsignedInteger('previous_score1')->nullable();
            $table->unsignedInteger('previous_score2')->nullable();
            $table->foreignId('previous_winner_entry_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->unsignedInteger('score1');
            $table->unsignedInteger('score2');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_overrides');
    }
};
