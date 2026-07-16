<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tombola_draws', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tombola_prize_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->timestamp('drawn_at');
            $table->timestamps();
            $table->index(['event_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tombola_draws');
    }
};
