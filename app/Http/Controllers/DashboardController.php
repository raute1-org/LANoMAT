<?php

namespace App\Http\Controllers;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Support\LinkedProviders;
use App\Modules\Registration\Support\MyRegistration;
use App\Modules\Seating\Support\MySeat;
use App\Modules\Teams\Support\MyTeams;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's personal overview. Composes core-module read models only
 * (current event, own registration/seat/teams, link status) — never queries
 * another module's tables itself.
 */
class DashboardController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function __invoke(Request $request, CurrentEvent $current): Response
    {
        $user = $this->authUser($request);
        $event = $current->get();

        return Inertia::render('Dashboard', [
            'event' => $event === null ? null : $this->eventDto($event),
            'registration' => $event === null ? null : MyRegistration::summary($event, $user),
            'seat' => $event === null ? null : MySeat::for($event, $user),
            'teams' => MyTeams::for($user),
            'steamLinked' => LinkedProviders::has($user, LinkedAccountProvider::Steam),
            'statusLabels' => trans('events.status'),
            'labels' => trans('dashboard'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventDto(Event $event): array
    {
        return [
            'name' => $event->name,
            'slug' => $event->slug,
            'status' => $event->status->value,
            'startsAt' => $event->starts_at?->toIso8601String(),
            'endsAt' => $event->ends_at?->toIso8601String(),
            'location' => $event->location,
        ];
    }
}
