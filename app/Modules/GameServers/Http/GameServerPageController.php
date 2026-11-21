<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\GameServers\Actions\ReloadMap;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use App\Modules\GameServers\Support\Cs2\GunGameConfig;
use App\Modules\GameServers\Support\ServerListProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GameServerPageController extends Controller
{
    /**
     * One server overview for every logged-in user: the current (or a chosen, `?event=`) LAN's
     * servers and the permanent servers (all year, independent of any LAN) together. There is
     * deliberately no public page for logged-out visitors.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewPermanent', ServerLink::class);

        $viewer = $request->user();
        $current = app(CurrentEvent::class)->get();

        if ($request->query('event') !== null) {
            $event = Event::query()->where('slug', (string) $request->query('event'))->firstOrFail();
            abort_unless($event->isPubliclyVisible(), 404);
        } else {
            $event = $current;
        }

        $servers = [];

        if ($event instanceof Event) {
            $servers = ServerListProjection::linksForEvent($event)->map(
                fn (ServerLink $link): array => ServerListProjection::dto($link, withPlayerList: true) + [
                    'isMine' => $viewer instanceof User && ServerListProjection::viewerPlaysIn($link->match, $viewer),
                ],
            )->values()->all();
        }

        $manageable = false;

        // `canManage` is decided here, per server, by the same policy the Actions enforce.
        // The admin props (map list, current map) exist only for those who may manage.
        $permanent = ServerListProjection::permanentLinks()->map(function (ServerLink $link) use ($viewer, &$manageable): array {
            $dto = ServerListProjection::permanentDto($link, withPlayerList: $viewer instanceof User);
            $canManage = $viewer instanceof User && $link->hasPelicanLink() && $viewer->can('configure', $link);

            if (! $canManage) {
                return $dto + ['canManage' => false];
            }

            $manageable = true;

            return $dto + [
                'canManage' => true,
                'admin' => [
                    'gunGame' => $link->preset_key === GunGameConfig::PRESET_KEY,
                    'canReloadMap' => ReloadMap::currentMap($link) !== null,
                    'running' => in_array($link->status, [ServerLinkStatus::Ready, ServerLinkStatus::NotResponding], true),
                ],
            ];
        })->values()->all();

        return Inertia::render('Servers/Index', [
            'event' => $event instanceof Event
                ? ['name' => $event->name, 'slug' => $event->slug, 'isCurrent' => $current?->is($event) ?? false]
                : null,
            'servers' => $servers,
            'permanent' => $permanent,
            'adminMaps' => $manageable ? Cs2Settings::MAPS : null,
            'labels' => trans('gameservers.page') + trans('gameservers.join_ui'),
            'permanentLabels' => trans('gameservers.permanent.page'),
            'overviewLabels' => trans('gameservers.overview'),
        ]);
    }

    /** The old per-event URL: kept alive as a permanent redirect into the one overview. */
    public function legacy(Event $event): RedirectResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $current = app(CurrentEvent::class)->get();

        return redirect()->to(
            $current?->is($event) ? '/servers' : '/servers?event='.urlencode($event->slug),
            301,
        );
    }
}
