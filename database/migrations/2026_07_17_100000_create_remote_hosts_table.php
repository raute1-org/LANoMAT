<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_hosts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('hostname');
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_user');
            $table->text('ssh_private_key'); // stored via Laravel 'encrypted' cast — ciphertext at rest
            $table->string('host_fingerprint')->nullable();
            $table->string('role')->default('generic');
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('unknown');
            $table->timestamp('last_probed_at')->nullable();
            $table->timestamps();
            $table->index('role');
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_hosts');
    }
};
