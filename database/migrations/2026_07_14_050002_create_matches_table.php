<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->string('bracket');
            $table->unsignedInteger('position');
            $table->foreignId('entry1_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->foreignId('entry2_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->unsignedInteger('score1')->nullable();
            $table->unsignedInteger('score2')->nullable();
            $table->foreignId('winner_entry_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            // next_match_id/loser_match_id are self-referencing FKs, added
            // below once the table exists (can't reference itself mid-create).
            $table->unsignedBigInteger('next_match_id')->nullable();
            $table->unsignedInteger('next_slot')->nullable();
            $table->unsignedBigInteger('loser_match_id')->nullable();
            $table->unsignedInteger('loser_slot')->nullable();
            $table->jsonb('discord_channels')->nullable();
            $table->jsonb('voice_channels')->nullable();
            $table->timestamps();
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->foreign('next_match_id')->references('id')->on('matches')->nullOnDelete();
            $table->foreign('loser_match_id')->references('id')->on('matches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
