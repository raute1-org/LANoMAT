<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_upload_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes');
            // tus upload id and the path of the finished file on the file host;
            // both are set by the pre-create hook, never by the client.
            $table->string('upload_id')->nullable()->unique();
            $table->string('stored_path')->nullable();
            // sha256 of the single-use upload ticket. The ticket itself is never stored.
            $table->string('ticket_hash', 64)->nullable()->unique();
            $table->timestamp('ticket_expires_at')->nullable();
            $table->string('status')->default('ticketed');
            // While active, the reservation counts against the user's quota until this moment.
            $table->timestamp('expires_at');
            $table->foreignId('shared_file_id')->nullable()->constrained('shared_files')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_upload_reservations');
    }
};
