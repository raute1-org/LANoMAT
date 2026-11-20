<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting indexes for the participant games library.
 *
 * The page reads recipes + their files (ordered by `sort`), every approved LAN
 * file, the servers of the running event and the event's visible tournaments.
 * The N+1 fix made those five reads constant, and these indexes keep them cheap
 * once the LAN has a full recipe catalogue and a season of tournaments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_recipes', function (Blueprint $table): void {
            // `published()` + `orderBy('title')` on every library request.
            $table->index(['status', 'title']);
        });

        Schema::table('game_recipe_files', function (Blueprint $table): void {
            // The `files()` relation filters by recipe and orders by sort/id.
            $table->index(['game_recipe_id', 'sort']);
        });

        Schema::table('server_links', function (Blueprint $table): void {
            // Permanent/event servers per game (library cards and game detail).
            $table->index(['game_id', 'permanent', 'status']);
        });

        Schema::table('tournaments', function (Blueprint $table): void {
            // The active event's publicly visible tournaments, ordered by start.
            $table->index(['event_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropIndex(['event_id', 'status', 'starts_at']);
        });

        Schema::table('server_links', function (Blueprint $table): void {
            $table->dropIndex(['game_id', 'permanent', 'status']);
        });

        Schema::table('game_recipe_files', function (Blueprint $table): void {
            $table->dropIndex(['game_recipe_id', 'sort']);
        });

        Schema::table('game_recipes', function (Blueprint $table): void {
            $table->dropIndex(['status', 'title']);
        });
    }
};
