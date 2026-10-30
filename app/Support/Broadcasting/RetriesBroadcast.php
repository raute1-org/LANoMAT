<?php

namespace App\Support\Broadcasting;

/**
 * Retry policy for `ShouldBroadcast` events. The queued `BroadcastEvent` job
 * reads `$tries`/`$backoff` from the event, so a short Reverb outage (restart,
 * deploy) no longer drops the push on the first failed attempt.
 */
trait RetriesBroadcast
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [2, 10];
}
