<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->integer('pos_x');
            $table->integer('pos_y');
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->unique(['event_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seats');
    }
};
