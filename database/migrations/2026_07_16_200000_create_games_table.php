<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon_path')->nullable();
            $table->unsignedInteger('min_team_size')->default(1);
            $table->unsignedInteger('max_team_size')->default(1);
            $table->string('pelican_egg_id')->nullable();
            $table->jsonb('default_server_config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
