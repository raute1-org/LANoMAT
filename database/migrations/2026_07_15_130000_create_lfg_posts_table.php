<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lfg_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->unsignedInteger('slots_needed')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['event_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lfg_posts');
    }
};
