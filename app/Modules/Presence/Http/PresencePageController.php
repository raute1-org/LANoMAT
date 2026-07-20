<?php

declare(strict_types=1);

namespace App\Modules\Presence\Http;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Friends\Support\FriendService;
use App\Modules\Presence\Events\PresenceUpdated;
use App\Modules\Presence\Support\PresenceProjection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PresencePageController extends Controller
{
    public function __construct(private readonly FriendService $friends) {}

    /**
     * The public presence board: who is checked in, where they're seated,
     * what's currently live, and which tournaments are still joinable — same
     * public visibility rule as the schedule/seating/servers pages (no auth
     * required).
     *
     * The projection itself is viewer-agnostic; this controller decorates
     * each participant with `isFriend` for the current viewer only, in the
     * Inertia payload alone. A guest (no authenticated user) sees `isFriend`
     * false for everyone. This is per-viewer private data and must never
     * leak into {@see PresenceUpdated} (which stays an empty broadcast
     * payload) or the beamer infoscreen scene (which never receives the
     * participant roster at all).
     */
    public function show(Request $request, Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $board = PresenceProjection::forEvent($event)->toArray();

        $viewer = $request->user();
        $friendIds = $viewer !== null ? $this->friends->friendUserIds($viewer) : [];

        $board['participants'] = array_map(
            fn (array $participant): array => $participant + [
                'isFriend' => in_array($participant['userId'], $friendIds, true),
            ],
            $board['participants'],
        );

        return Inertia::render('Presence/Index', [
            'event' => ['id' => $event->id, 'name' => $event->name, 'slug' => $event->slug],
            'presence' => $board,
            'labels' => trans('presence'),
        ]);
    }
}
