<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_links', function (Blueprint $table): void {
            // A server that runs as a Docker container on a managed remote host and is controlled
            // over SSH (power, console, logs). Additive: every existing row keeps these null and
            // the control driver is derived (see ServerLink::controlDriver()), so nothing to backfill.
            $table->foreignId('remote_host_id')->nullable()->after('custom_server_id')
                ->constrained('remote_hosts')->nullOnDelete();
            $table->string('remote_container', 128)->nullable()->after('remote_host_id');
            // Which console tool the container ships (allowlist, see ConsoleKind), e.g. rcon-cli.
            $table->string('console_kind', 32)->nullable()->after('remote_container');
        });
    }

    public function down(): void
    {
        Schema::table('server_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('remote_host_id');
            $table->dropColumn(['remote_container', 'console_kind']);
        });
    }
};
