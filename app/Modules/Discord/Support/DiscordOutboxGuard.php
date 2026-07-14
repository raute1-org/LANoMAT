<?php

namespace App\Modules\Discord\Support;

use App\Modules\Discord\Models\DiscordOutbox;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DiscordOutboxGuard
{
    /**
     * Run $send exactly once per dedup_key. Returns true if it fired now.
     */
    public function once(string $dedupKey, string $kind, Closure $send): bool
    {
        try {
            // Wrapped in DB::transaction() so Laravel issues a SAVEPOINT when
            // already inside an outer transaction (e.g. tests using
            // RefreshDatabase). Without this, a unique-violation on Postgres
            // aborts the entire outer transaction, not just this statement.
            DB::transaction(function () use ($dedupKey, $kind): void {
                DiscordOutbox::create(['kind' => $kind, 'dedup_key' => $dedupKey]);
            });
        } catch (QueryException $e) {
            // Unique violation: already sent (or racing) -> do not send again.
            return false;
        }

        $send();

        DiscordOutbox::where('dedup_key', $dedupKey)->update(['sent_at' => now()]);

        return true;
    }
}
