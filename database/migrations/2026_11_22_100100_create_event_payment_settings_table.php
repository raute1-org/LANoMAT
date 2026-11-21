<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_payment_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('payment_link', 255)->nullable();
            $table->string('payment_note', 160)->nullable();
            $table->unsignedSmallInteger('reminder_after_days')->default(3);
            $table->unsignedSmallInteger('reminder_before_event_days')->default(3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_payment_settings');
    }
};
