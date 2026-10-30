<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table): void {
            $table->date('arrival_day')->nullable();
            $table->string('arrival_window', 16)->nullable();
            $table->date('departure_day')->nullable();
            $table->string('departure_window', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn(['arrival_day', 'arrival_window', 'departure_day', 'departure_window']);
        });
    }
};
