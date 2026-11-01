<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `matches.status` is an unconstrained string column, so the new `live`
     * value needs no schema change. Before it existed, `GoLive` flipped a
     * `Warmup` match back to `ready`, and `warmup_started_at` is stamped once
     * by `EnterWarmup` and never cleared. A `ready` row with that timestamp can
     * therefore only be a match that went live (the sole Warmup -> Ready path
     * is `GoLive`), so it is safely turned into `live`. `ready` rows without
     * the timestamp (never warmed up: no game server, or still waiting) are
     * left alone: they cannot be told apart from the data.
     */
    public function up(): void
    {
        DB::table('matches')
            ->where('status', 'ready')
            ->whereNotNull('warmup_started_at')
            ->update(['status' => 'live']);
    }

    public function down(): void
    {
        DB::table('matches')->where('status', 'live')->update(['status' => 'ready']);
    }
};
