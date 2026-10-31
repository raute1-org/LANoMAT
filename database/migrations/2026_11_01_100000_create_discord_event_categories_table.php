<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Discord categories of one event: where its live match channels go ("live") and
        // where finished match text channels are kept ("archive"). Discord allows 50 channels
        // per category, so a busy event can own several of each, told apart by `sequence`.
        Schema::create('discord_event_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('category_id');
            $table->timestamps();

            $table->unique(['event_id', 'kind', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_event_categories');
    }
};
