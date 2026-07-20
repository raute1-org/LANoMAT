<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');              // LinkedAccountProvider backing value
            $table->string('provider_user_id');      // SteamID / Twitch user id
            $table->string('nickname')->nullable();  // provider-side display name
            $table->text('access_token')->nullable();   // encrypted (Twitch); null for Steam
            $table->text('refresh_token')->nullable();  // encrypted (Twitch)
            $table->timestamp('token_expires_at')->nullable();
            $table->jsonb('scopes')->nullable();
            $table->jsonb('meta')->nullable();       // e.g. {"needs_reauth": true}
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']); // an external account maps to ONE user
            $table->unique(['user_id', 'provider']);          // one account per provider per user
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_accounts');
    }
};
