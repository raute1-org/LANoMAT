<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fleet registry for the LANoMAT launcher: one row per gaming terminal
 * (arcade cabinet, living-room PC, laptop), upserted by the launcher's
 * heartbeat. The pairing is the MCP device flow, so `mcp_token_id` is the
 * stable identity — a fresh token claim of the same machine writes the same
 * row, a revoked and re-paired token starts a new one.
 *
 * `state` is what the terminal last *reported*; the monitor derives "offline"
 * from `last_seen_at` (see LauncherTerminal::derivedState()), so a terminal
 * that vanished without a goodbye heartbeat still turns grey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launcher_terminals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mcp_token_id')->nullable()->unique()->constrained('mcp_tokens')->nullOnDelete();
            $table->string('name', 60);
            $table->string('os', 16)->nullable();
            $table->string('arch', 16)->nullable();
            $table->string('version', 32)->nullable();
            $table->string('state', 16)->default('offline')->index();
            $table->string('current_game_slug', 120)->nullable();
            // Published recipe slugs installed on this machine, for the
            // monitor's "what can this cabinet play right now" view.
            $table->jsonb('installed')->nullable();
            $table->boolean('gamepad')->default(false);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launcher_terminals');
    }
};
