<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('remote_host_id')->constrained('remote_hosts')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('image');
            $table->text('command')->nullable();
            $table->string('ports')->nullable();
            $table->jsonb('env')->nullable();
            $table->string('container_name');
            $table->string('status')->default('stopped');
            $table->text('last_output')->nullable();
            $table->timestamps();
            $table->index('remote_host_id');
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_servers');
    }
};
