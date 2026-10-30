<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Pelican Client API (power, resources) addresses a server by its
        // short `identifier`, not the numeric Application API id kept in
        // pelican_server_id. Nullable: rows created before this column (and
        // manual links) simply have none.
        Schema::table('server_links', function (Blueprint $table): void {
            $table->string('pelican_server_identifier')->nullable()->after('pelican_server_id');
        });
    }

    public function down(): void
    {
        Schema::table('server_links', function (Blueprint $table): void {
            $table->dropColumn('pelican_server_identifier');
        });
    }
};
