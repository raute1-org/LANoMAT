<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            // Nullable on purpose: most games have no checkable ownership
            // mapping, which is the common "Unknown" case in
            // GameOwnershipHint and deliberately produces no warning noise
            // (see app/Modules/Identity/Support/GameOwnershipHint.php).
            $table->string('provider')->nullable()->after('pelican_egg_id');
            $table->string('provider_app_id')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn(['provider', 'provider_app_id']);
        });
    }
};
