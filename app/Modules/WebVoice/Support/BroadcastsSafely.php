<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Support;

use Throwable;

/**
 * Broadcasting is best-effort: the row is already saved, so a Reverb outage must not turn a
 * successful POST into a 500 that makes the client retry and post the message twice.
 */
trait BroadcastsSafely
{
    private function broadcastSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
