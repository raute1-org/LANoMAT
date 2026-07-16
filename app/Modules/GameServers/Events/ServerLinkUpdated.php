<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Events;

use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever a {@see ServerLink}'s player-facing state changes:
 * {@see PollServerStatusJob} on Ready/Failed,
 * and {@see SetManualJoinInfo} on a manual
 * fallback write. Task 5 consumes this to update the match page and the
 * Discord embed — kept a plain (non-broadcast) event here since Task 5 owns
 * the decision of whether/how it reaches the browser.
 */
class ServerLinkUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly ServerLink $serverLink,
    ) {}
}
