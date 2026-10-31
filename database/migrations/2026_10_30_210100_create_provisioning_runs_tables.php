<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('plan');
            // One plan per target at a time (partial unique index below).
            $table->string('target_key');
            $table->foreignId('hypervisor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->json('options');
            // Encrypted json: typed plan config + runtime state. Never holds secrets.
            $table->text('context');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        DB::statement("create unique index provisioning_runs_active_target_unique on provisioning_runs (target_key) where status in ('pending', 'running')");

        Schema::create('provisioning_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provisioning_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('step');
            $table->string('status')->default('pending');
            $table->boolean('skipped')->default(false);
            $table->text('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['provisioning_run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_run_steps');
        Schema::dropIfExists('provisioning_runs');
    }
};
