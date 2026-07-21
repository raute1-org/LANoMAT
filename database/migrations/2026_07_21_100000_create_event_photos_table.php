<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('caption')->nullable();
            $table->boolean('is_highlight')->default(false);
            $table->string('visibility')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_photos');
    }
};
