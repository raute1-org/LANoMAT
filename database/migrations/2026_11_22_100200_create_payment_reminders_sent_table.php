<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminders_sent', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_registration_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->timestamp('sent_at');
            $table->unique(['event_registration_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminders_sent');
    }
};
