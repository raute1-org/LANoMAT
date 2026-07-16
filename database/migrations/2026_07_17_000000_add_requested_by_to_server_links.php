<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_links', function (Blueprint $table): void {
            // Nullable and only ever set by the interactive manual path
            // (SetManualJoinInfo's $actor) — the automatic
            // ProvisionMatchServerJob path has no interactive requester, so
            // it deliberately leaves this null (see GuardrailPolicy's
            // per-user-cap doc). Not "who owns this server", just "who typed
            // it in", so it is nullOnDelete rather than cascading.
            $table->foreignId('requested_by')->nullable()->after('manual')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('server_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by');
        });
    }
};
