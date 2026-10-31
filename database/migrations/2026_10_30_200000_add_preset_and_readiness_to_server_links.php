<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_links', function (Blueprint $table): void {
            // Event-level servers (started from a preset by the orga) belong to an event
            // directly, not through a match or tournament. Null for match servers.
            $table->foreignId('event_id')->nullable()->after('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->nullable()->after('event_id')->constrained()->nullOnDelete();
            $table->string('title')->nullable()->after('game_id');
            $table->string('preset_key')->nullable()->after('title');
            // Snapshot of the preset's readiness/verification settings at creation time, so a
            // later preset edit does not change a running server's checks.
            $table->jsonb('readiness')->nullable();
            // Last answer of the A2S query (name, map, players ...) and when it was taken.
            $table->jsonb('query_info')->nullable();
            $table->timestamp('queried_at')->nullable();
            // Reinstalls LANoMAT triggered after a failed install verification (retry once).
            $table->unsignedSmallInteger('install_attempts')->default(0);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('server_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('event_id');
            $table->dropConstrainedForeignId('game_id');
            $table->dropColumn(['title', 'preset_key', 'readiness', 'query_info', 'queried_at', 'install_attempts']);
        });
    }
};
