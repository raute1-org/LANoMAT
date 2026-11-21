<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The server-side SHA-256 of the stored bytes, so a launcher can verify
        // every matched LAN file instead of only its size (or an orga-pinned
        // recipe hash). Null means "not hashed yet" (rows from before this
        // column, or a store that was unreadable at upload time); backfill with
        // `lanomat:files:hash`.
        Schema::table('shared_files', function (Blueprint $table) {
            $table->char('sha256', 64)->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('shared_files', function (Blueprint $table) {
            $table->dropColumn('sha256');
        });
    }
};
