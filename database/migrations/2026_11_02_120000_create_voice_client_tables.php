<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Device authorization requests of the native voice client. Only sha256 digests of the
        // device code and the user code are stored, never the codes themselves.
        Schema::create('voice_client_device_codes', function (Blueprint $table) {
            $table->id();
            $table->char('device_code_hash', 64)->unique();
            $table->char('user_code_hash', 64)->unique();
            $table->string('device_name', 60);
            $table->string('status', 16)->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('interval');
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        // Long-lived bearer tokens of paired devices (sha256 digest only).
        Schema::create('voice_client_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_name', 60);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_client_tokens');
        Schema::dropIfExists('voice_client_device_codes');
    }
};
