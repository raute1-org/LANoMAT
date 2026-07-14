<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('discord_outbox', function (Blueprint $table) {
            // Persisted at enqueue time so a failed send can be replayed
            // verbatim by the sweep command without re-deriving the message
            // from the dedup key (which would be brittle string-parsing).
            $table->string('channel_id')->nullable()->after('dedup_key');
            $table->text('content')->nullable()->after('channel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discord_outbox', function (Blueprint $table) {
            $table->dropColumn(['channel_id', 'content']);
        });
    }
};
