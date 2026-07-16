<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tombola_prizes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->index(['event_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tombola_prizes');
    }
};
