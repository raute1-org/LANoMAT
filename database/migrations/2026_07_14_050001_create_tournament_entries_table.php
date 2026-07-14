<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->unsignedInteger('seed')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->jsonb('roster_snapshot')->nullable();
            $table->string('status')->default('registered');
            $table->timestamps();
        });

        // Exactly one of team_id/user_id must be set (solo vs. team entry).
        DB::statement('ALTER TABLE tournament_entries ADD CONSTRAINT entry_exactly_one_owner CHECK ((team_id IS NULL) <> (user_id IS NULL))');

        Schema::table('tournaments', function (Blueprint $table) {
            $table->foreign('winner_entry_id')->references('id')->on('tournament_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropForeign(['winner_entry_id']);
        });

        DB::statement('ALTER TABLE tournament_entries DROP CONSTRAINT entry_exactly_one_owner');

        Schema::dropIfExists('tournament_entries');
    }
};
