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
            // Only a unique-key violation on dedup_key means "already sent
            // (or racing)" -> do not send again. Any other failure (e.g. a
            // connection error, a schema problem) is a real error and must
            // not be silently swallowed as if it were a dedup no-op.
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            return false;
        }

        $send();

        DiscordOutbox::where('dedup_key', $dedupKey)->update(['sent_at' => now()]);

        return true;
    }
}
