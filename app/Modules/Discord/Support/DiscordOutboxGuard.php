<?php

namespace App\Modules\Discord\Support;

use App\Modules\Discord\Models\DiscordOutbox;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DiscordOutboxGuard
{
    /**
     * Run $send exactly once per dedup_key. Returns true if it fired (now or
     * — for a row left over from a previously failed attempt — on this
     * retry).
     *
     * $channelId and $content are persisted alongside the dedup row so that,
     * if $send() throws (row stays sent_at IS NULL), SweepOutboxCommand can
     * replay the exact same message later instead of trying to re-derive it
     * from the dedup key.
     *
     * Only a row with sent_at IS NOT NULL counts as "already done" — a row
     * that exists but was never marked sent means a previous attempt's
     * $send() threw (e.g. Discord unreachable), and the insert's unique
     * violation on dedup_key must not be mistaken for that case having
     * already succeeded. Otherwise a queued job's automatic retry would find
     * its own leftover row and silently no-op forever, permanently losing
     * the send instead of genuinely retrying it.
     */
    public function once(string $dedupKey, string $kind, Closure $send, ?string $channelId = null, ?string $content = null): bool
    {
        try {
            // Wrapped in DB::transaction() so Laravel issues a SAVEPOINT when
            // already inside an outer transaction (e.g. tests using
            // RefreshDatabase). Without this, a unique-violation on Postgres
            // aborts the entire outer transaction, not just this statement.
            DB::transaction(function () use ($dedupKey, $kind, $channelId, $content): void {
                DiscordOutbox::create([
                    'kind' => $kind,
                    'dedup_key' => $dedupKey,
                    'channel_id' => $channelId,
                    'content' => $content,
                ]);
            });
        } catch (QueryException $e) {
            // Only a unique-key violation on dedup_key means a row already
            // exists for this key. Any other failure (e.g. a connection
            // error, a schema problem) is a real error and must not be
            // silently swallowed as if it were a dedup no-op.
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            $existing = DiscordOutbox::where('dedup_key', $dedupKey)->first();

            if ($existing?->sent_at !== null) {
                // Genuinely already sent (or a concurrent attempt is
                // currently sending) -> do not send again.
                return false;
            }

            // The existing row was never marked sent: a previous attempt's
            // $send() threw and left it behind. This call is a legitimate
            // retry (e.g. the queue re-running a failed job) -> fall through
            // and actually resend, reusing the existing row.
        }

        $send();

        DiscordOutbox::where('dedup_key', $dedupKey)->update(['sent_at' => now()]);

        return true;
    }
}
