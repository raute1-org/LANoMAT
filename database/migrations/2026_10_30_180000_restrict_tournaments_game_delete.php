<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A game used by tournaments must not silently null game_id on delete
        // (the application guard blocks it with a German message first).
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropForeign(['game_id']);
            $table->foreign('game_id')->references('id')->on('games')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropForeign(['game_id']);
            $table->foreign('game_id')->references('id')->on('games')->nullOnDelete();
        });
    }
};
