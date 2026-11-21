<?php

use App\Modules\Lighting\Support\DefaultScenes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lighting_settings', function (Blueprint $table) {
            $table->id();
            // null = follow config('lighting.allow_strobe'); strobe is locked by default.
            $table->boolean('strobe_allowed')->nullable();
            $table->timestamps();
        });

        Schema::create('lighting_scenes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('states')->nullable();
            $table->json('pattern')->nullable();
            $table->boolean('blackout')->default(false);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('lighting_rules', function (Blueprint $table) {
            $table->id();
            $table->string('trigger');
            $table->foreignId('lighting_scene_id')->constrained('lighting_scenes')->cascadeOnDelete();
            $table->boolean('active')->default(false);
            $table->unsignedInteger('cooldown_seconds')->default(30);
            // Optional scope. Plain ids on purpose: no foreign keys into other modules' tables.
            $table->unsignedBigInteger('event_id')->nullable();
            $table->unsignedBigInteger('tournament_id')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();

            $table->index(['trigger', 'active']);
        });

        DefaultScenes::seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('lighting_rules');
        Schema::dropIfExists('lighting_scenes');
        Schema::dropIfExists('lighting_settings');
    }
};
