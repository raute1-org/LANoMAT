<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens for the LANoMAT MCP server and the agent installer, plus the
 * device-code pairs used to mint them from a browser login.
 *
 * Only SHA-256 digests are stored (same rule as the native voice client):
 * a dumped database never contains a usable token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_device_codes', function (Blueprint $table): void {
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

        Schema::create('mcp_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->char('token_hash', 64)->unique();
            // Reserved for narrower agent tokens ("read", "install", ...). The
            // policies still decide every request; this list only lets a token
            // be scoped down later without another migration.
            $table->jsonb('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
        Schema::dropIfExists('mcp_device_codes');
    }
};
