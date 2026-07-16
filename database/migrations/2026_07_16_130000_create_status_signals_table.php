<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('component');
            $table->string('level');
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'component', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_signals');
    }
};
