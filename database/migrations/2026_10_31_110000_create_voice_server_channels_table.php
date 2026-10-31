<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Voice channels created for an event-level game server (one per
        // provider). The Voice module owns this table; `server_link_id` is a
        // plain reference (no foreign key into GameServers' tables).
        Schema::create('voice_server_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_link_id');
            $table->string('provider');
            $table->unsignedBigInteger('channel_id');
            $table->timestamps();

            $table->unique(['server_link_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_server_channels');
    }
};
