<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jukebox_skip_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jukebox_item_id')->constrained('jukebox_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['jukebox_item_id', 'user_id']); // one skip-vote per user per item
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jukebox_skip_votes');
    }
};
