<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-row settings for the room receiver. The row is created on demand by
     * RoomAudioSetting::current(); the ceiling caps every volume command.
     */
    public function up(): void
    {
        Schema::create('room_audio_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('volume_ceiling')->default(60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_audio_settings');
    }
};
