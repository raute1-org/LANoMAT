<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Http;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Support\ServerListProjection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameServerPageController extends Controller
{
    /**
     * The public server list: every Ready game server for the event's
     * matches/tournaments, address/port/connect-string first — same public
     * visibility rule as the schedule/seating/LFG pages (no auth required).
     */
    public function index(Request $request, Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        return Inertia::render('Servers/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'servers' => ServerListProjection::forEvent($event, withPlayerList: $request->user() !== null),
            'labels' => trans('gameservers.page') + trans('gameservers.join_ui'),
        ]);
    }
}
