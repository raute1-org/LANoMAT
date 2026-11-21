<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // No foreign key: the audit row must survive a deleted conversation.
            $table->string('conversation_id', 64)->index();
            $table->string('tool_call_id')->nullable();
            $table->string('tool');
            $table->jsonb('arguments');
            $table->string('arguments_hash', 64)->index();
            $table->string('status', 16)->default('proposed')->index();
            $table->text('secret')->nullable();
            $table->text('result_summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'tool_call_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_actions');
    }
};
