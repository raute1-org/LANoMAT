<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opt-out of showing where one is (voice room, game server) to the other participants.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('share_location')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('share_location');
        });
    }
};
