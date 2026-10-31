<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_links', function (Blueprint $table): void {
            // Permanent servers run all year, independent of any event, match or
            // tournament: event_id/match_id/tournament_id stay null and no
            // lifecycle listener touches them.
            $table->boolean('permanent')->default(false)->after('manual');
            // Where the server comes from: pelican (default, every existing row),
            // docker (a container on a managed remote host) or external (registered only).
            $table->string('source')->default('pelican')->after('permanent');
            // Pelican source only: the node the server was placed on.
            $table->string('pelican_node_id')->nullable()->after('source');
            // Docker source only: the container definition (CustomServers module).
            $table->foreignId('custom_server_id')->nullable()->after('pelican_node_id')
                ->constrained('custom_servers')->cascadeOnDelete();
            // How status and players are asked for: a2s or minecraft (null: not at all).
            $table->string('query_protocol')->nullable()->after('custom_server_id');
            // Free text shown on the participant page (how to join, mods, rules).
            $table->text('description')->nullable()->after('title');
            $table->index(['permanent', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('server_links', function (Blueprint $table): void {
            $table->dropIndex(['permanent', 'status']);
            $table->dropConstrainedForeignId('custom_server_id');
            $table->dropColumn(['permanent', 'source', 'pelican_node_id', 'query_protocol', 'description']);
        });
    }
};
