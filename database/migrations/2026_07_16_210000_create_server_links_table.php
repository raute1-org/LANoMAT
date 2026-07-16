<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_id')->nullable()->constrained('matches')->cascadeOnDelete();
            $table->foreignId('tournament_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('pelican_server_id')->nullable();
            $table->jsonb('join_info')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('manual')->default(false);
            $table->timestamps();
            $table->index('match_id');
            $table->index('tournament_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_links');
    }
};
