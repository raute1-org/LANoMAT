<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The WebVoice module owns these tables. `game_match_id` / `tournament_entry_id` are plain
        // references into Tournaments (no foreign key across module boundaries, same as
        // voice_server_channels); users are core and keep their foreign keys.
        Schema::create('web_voice_channels', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('name', 80);
            $table->string('description', 160)->nullable();
            $table->unsignedBigInteger('game_match_id')->nullable()->index();
            $table->unsignedBigInteger('tournament_entry_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // One room per entry of a match: makes provisioning idempotent.
            $table->unique(['game_match_id', 'tournament_entry_id']);
            $table->index(['kind', 'archived_at']);
        });

        // Access list of a match channel: a snapshot of the team roster taken when the room is made.
        Schema::create('web_voice_channel_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_voice_channel_id')->constrained('web_voice_channels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unique(['web_voice_channel_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('web_voice_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_voice_channel_id')->constrained('web_voice_channels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['web_voice_channel_id', 'id']);
        });

        Schema::create('web_voice_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_voice_channel_id')->constrained('web_voice_channels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('muted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['web_voice_channel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_voice_mutes');
        Schema::dropIfExists('web_voice_messages');
        Schema::dropIfExists('web_voice_channel_user');
        Schema::dropIfExists('web_voice_channels');
    }
};
