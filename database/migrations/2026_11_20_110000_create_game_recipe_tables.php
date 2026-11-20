<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Game recipes: the installable "how do I get this game onto my PC at the
 * LAN" knowledge, authored as YAML + SKILL.md and exposed to the agent as
 * MCP tools and Agent Skills. Rows are upserted by slug from a recipe source
 * (bundled directory, SMB share or git repository).
 *
 * `game_recipe_files` is deliberately one table for required game files
 * (ISO/patch/mod/tool) and avatar/content packs: both are matched against
 * SharedFiles uploaded to the LAN file host, and a null recipe id makes an
 * avatar available to every recipe. `shared_file_id` pins a specific upload
 * when a filename pattern would be ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_recipes', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->jsonb('aliases')->nullable();
            $table->string('version')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->string('source', 16)->default('bundled');
            $table->string('source_ref')->nullable();
            $table->jsonb('platforms')->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('homepage')->nullable();
            $table->string('license')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->text('skill_markdown')->nullable();
            $table->jsonb('requirements')->nullable();
            $table->jsonb('steps')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignId('contributor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('game_recipe_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_recipe_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role', 24)->default('content');
            $table->string('label');
            $table->jsonb('match_patterns')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('url')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->foreignId('shared_file_id')->nullable()->constrained('shared_files')->nullOnDelete();
            $table->timestamps();

            $table->index(['role', 'game_recipe_id']);
            $table->index('shared_file_id');
        });

        Schema::create('game_recipe_installs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('os', 16)->nullable();
            $table->string('status', 16)->default('planned')->index();
            $table->jsonb('plan')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_recipe_installs');
        Schema::dropIfExists('game_recipe_files');
        Schema::dropIfExists('game_recipes');
    }
};
