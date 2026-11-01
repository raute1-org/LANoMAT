<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local avatar store. `avatar_url` stays the remembered Discord source URL; the processed
     * square copy lives on the private disk (`avatar_path`) and `avatar_hash` addresses it
     * (content hash, part of every public URL). No data migration: `lanomat:refresh-avatars --all`
     * fills the rows once after the deploy, and the daily run keeps them current.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable();
            $table->string('avatar_hash', 16)->nullable();
            $table->timestamp('avatar_checked_at')->nullable();
            $table->timestamp('last_login_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_login_at']);
            $table->dropColumn(['avatar_path', 'avatar_hash', 'avatar_checked_at', 'last_login_at']);
        });
    }
};
