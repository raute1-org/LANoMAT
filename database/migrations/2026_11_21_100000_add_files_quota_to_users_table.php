<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-user upload quota (MB, per event) that replaces files.per_user_quota_mb; null = the configured default.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('files_quota_mb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('files_quota_mb');
        });
    }
};
