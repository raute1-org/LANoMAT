<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hypervisors', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('base_url');
            $table->string('node');
            $table->string('token_id');
            // Encrypted cast (text: ciphertext is longer than the secret).
            $table->text('token_secret');
            // Optional SPKI pin (`sha256//base64`) enforced by curl for self-signed certificates.
            $table->string('tls_pin')->nullable();
            // Optional SSH access to the hypervisor host, used as the independent
            // path to read the new VM's host key (see HostKeyTrust).
            $table->foreignId('jump_host_id')->nullable()->constrained('remote_hosts')->nullOnDelete();
            $table->string('last_version')->nullable();
            $table->timestamp('last_probed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hypervisors');
    }
};
