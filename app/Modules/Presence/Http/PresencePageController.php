<?php

declare(strict_types=1);

namespace App\Modules\Presence\Http;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Presence\Support\PresenceProjection;
use Inertia\Inertia;
use Inertia\Response;

class PresencePageController extends Controller
{
    /**
     * The public presence board: who is checked in, where they're seated,
     * what's currently live, and which tournaments are still joinable — same
     * public visibility rule as the schedule/seating/servers pages (no auth
     * required).
     */
    public function show(Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        return Inertia::render('Presence/Index', [
            'event' => ['id' => $event->id, 'name' => $event->name, 'slug' => $event->slug],
            'presence' => PresenceProjection::forEvent($event)->toArray(),
            'labels' => trans('presence'),
        ]);
    }
}
