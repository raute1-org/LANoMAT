<?php

namespace App\Modules\Discord\Listeners;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;

/**
 * Records surfaced gateway events (member join/leave, message, reaction) at
 * info level — the concrete extension point until product behaviour is
 * specified. Registered explicitly per event in
 * {@see AppServiceProvider::configureEventListeners()}, since
 * the repo's listener registration is explicit `Event::listen` mappings
 * rather than type-hinted auto-discovery.
 */
class LogGatewayEvent
{
    public function handle(object $event): void
    {
        Log::info('discord.gateway.event', ['event' => $event::class] + get_object_vars($event));
    }
}
