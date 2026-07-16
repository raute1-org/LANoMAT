<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_item_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->unique(['schedule_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_item_favorites');
    }
};
