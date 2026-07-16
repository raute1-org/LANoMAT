<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infoscreen_scenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->jsonb('config')->nullable();
            $table->unsignedInteger('duration_sec')->default(15);
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['event_id', 'enabled', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infoscreen_scenes');
    }
};
