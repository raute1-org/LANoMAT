<?php

namespace App\Modules\Discord\Support;

use App\Modules\Discord\Models\DiscordOutbox;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DiscordOutboxGuard
{
    /**
     * How long a sent_at IS NULL row is treated as a live in-flight attempt
     * before it is considered abandoned and eligible for resend.
     *
     * Must stay comfortably below the queue connection's `retry_after`
     * (default 90s, see config/queue.php) — that setting is how long the
     * queue waits before assuming a worker died and releasing the job back
     * for a retry. As long as the lease is well under that window, a retry
     * triggered by `retry_after` can never race a still-genuinely-running
     * first attempt: by the time the queue would even consider the job
     * retryable, this lease has already expired, so there is no gap where
     * two workers both believe they are entitled to send.
     */
    public const IN_FLIGHT_LEASE_SECONDS = 30;

    /**
     * Run $send exactly once per dedup_key. Returns true if it fired (now or
     * — for a row left over from a previously failed/abandoned attempt — on
     * this retry).
     *
     * $channelId and $content are persisted alongside the dedup row so that,
     * if $send() throws (row stays sent_at IS NULL), SweepOutboxCommand can
     * replay the exact same message later instead of trying to re-derive it
     * from the dedup key.
     *
     * A row with sent_at IS NOT NULL always counts as "already done". A row
     * with sent_at IS NULL is ambiguous: it means either (a) a previous
     * attempt's $send() threw (e.g. Discord unreachable) and left the row
     * behind, or (b) another attempt for the same key is concurrently
     * running right now, still inside its own $send() call. Case (a) must
     * fall through and resend, reusing the existing row — otherwise a
     * queued job's automatic retry would find its own leftover row and
     * silently no-op forever, permanently losing the send. Case (b) must
     * NOT resend, or two in-flight attempts would both send, producing a
     * duplicate Discord message (or a second orphaned channel whose id is
     * never stored). The two are told apart by staleness: only a row older
     * than IN_FLIGHT_LEASE_SECONDS is assumed abandoned rather than live.
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

            // The unique violation guarantees a row exists for this key at
            // (or just before) this point, so a null result here would only
            // mean it was deleted concurrently between the insert attempt
            // and this lookup — vanishingly unlikely, but handled safely by
            // falling through to the same "not blocked" path as a stale row.
            $existing = DiscordOutbox::where('dedup_key', $dedupKey)->first();

            if ($existing !== null) {
                if ($existing->sent_at !== null) {
                    // Genuinely already sent -> do not send again.
                    return false;
                }

                // sent_at IS NULL: either abandoned (safe to resend) or
                // another attempt is currently in flight (must not resend).
                // Only treat it as abandoned once it is older than the
                // in-flight lease — a fresh row is assumed to belong to a
                // concurrent attempt that is still running its own $send()
                // right now.
                $updatedAt = $existing->updated_at ?? $existing->created_at;

                if ($updatedAt !== null && $updatedAt->gt(now()->subSeconds(self::IN_FLIGHT_LEASE_SECONDS))) {
                    // Fresh NULL row -> a concurrent attempt is currently
                    // sending. Do not send again.
                    return false;
                }
            }

            // The existing row was never marked sent and is stale (or, in
            // the vanishingly unlikely case above, gone): a previous
            // attempt's $send() threw and left it behind (or the worker died
            // mid-send well past the in-flight window). This call is a
            // legitimate retry (e.g. the queue re-running a failed job, or
            // SweepOutboxCommand) -> fall through and actually resend,
            // reusing the existing row.
        }

        $send();

        DiscordOutbox::where('dedup_key', $dedupKey)->update(['sent_at' => now()]);

        return true;
    }
}
