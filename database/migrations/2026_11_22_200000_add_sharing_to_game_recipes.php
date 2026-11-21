<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Game sharing: a recipe a participant shares can be put on the player
 * surfaces by the orga (`in_lineup`, next to the configured LAUNCHER_LINEUP),
 * and it travels back into the git catalogue as a pull request. The `pr_*`
 * columns are the local record of that pull request; they are written by the
 * proposal job and the status sync only, never by a form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_recipes', function (Blueprint $table): void {
            $table->boolean('in_lineup')->default(false);
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_url')->nullable();
            $table->string('pr_branch')->nullable();
            $table->string('pr_state', 16)->nullable()->index();
            $table->text('pr_error')->nullable();
            $table->timestamp('pr_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_recipes', function (Blueprint $table): void {
            $table->dropIndex(['pr_state']);
            $table->dropColumn(['in_lineup', 'pr_number', 'pr_url', 'pr_branch', 'pr_state', 'pr_error', 'pr_synced_at']);
        });
    }
};
