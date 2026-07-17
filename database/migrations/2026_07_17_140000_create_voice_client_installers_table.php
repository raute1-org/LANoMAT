<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_client_installers', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('platform');
            $table->string('version');
            $table->string('path');
            $table->string('original_name');
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            // No partial unique index on (provider, platform) WHERE is_current
            // — kept portable across drivers. Single-current-per-(provider,
            // platform) is instead enforced in SetCurrentInstaller's
            // transaction (unset any previous current, then set the target).
            $table->index(['provider', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_client_installers');
    }
};
