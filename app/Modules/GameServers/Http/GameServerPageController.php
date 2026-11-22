<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\GameServers\Actions\ReloadMap;
use App\Modules\GameServers\Enums\ControlDriver;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use App\Modules\GameServers\Support\Cs2\GunGameConfig;
use App\Modules\GameServers\Support\RemoteQuickCommands;
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

        $slug = $request->query('event');

        if ($slug !== null) {
            abort_unless(is_string($slug), 404);

            $event = Event::query()->where('slug', $slug)->firstOrFail();
            abort_unless($event->isPubliclyVisible(), 404);
        } else {
            $event = $current;
        }

        $servers = [];

        // One resolver for the whole page: one recipe-catalogue read and one location-snapshot
        // read for all rows, never one per server.
        $install = ServerListProjection::installLinks();
        $occupants = ServerListProjection::occupants();

        if ($event instanceof Event) {
            $servers = ServerListProjection::linksForEvent($event)->map(
                fn (ServerLink $link): array => ServerListProjection::dto(
                    $link,
                    withPlayerList: true,
                    install: $install,
                    occupants: $occupants,
                    occupantEvent: $event,
                    occupantViewer: $viewer,
                ) + [
                    'isMine' => $viewer instanceof User && ServerListProjection::viewerPlaysIn($link->match, $viewer),
                ],
            )->values()->all();
        }

        $manageable = false;

        // `canManage` is decided here, per server, by the same policy the Actions enforce.
        // The admin props (map list, current map) exist only for those who may manage.
        $permanent = ServerListProjection::permanentLinks()->map(function (ServerLink $link) use ($viewer, $install, $occupants, $current, &$manageable): array {
            // Permanent servers belong to no event: their occupant line is the current LAN's.
            $dto = ServerListProjection::permanentDto(
                $link,
                withPlayerList: $viewer instanceof User,
                install: $install,
                occupants: $occupants,
                occupantEvent: $current,
                occupantViewer: $viewer,
            );
            $driver = $link->controlDriver();
            $canManage = $viewer instanceof User
                && ($driver === ControlDriver::RemoteDocker || ($driver === ControlDriver::Pelican && $link->hasPelicanLink()))
                && $viewer->can('configure', $link);

            if (! $canManage) {
                return $dto + ['canManage' => false];
            }

            $manageable = true;

            if ($driver === ControlDriver::RemoteDocker) {
                return $dto + [
                    'canManage' => true,
                    'admin' => [
                        'gunGame' => false,
                        'canReloadMap' => false,
                        'running' => $link->status !== ServerLinkStatus::Offline,
                        'remote' => [
                            'quickCommands' => RemoteQuickCommands::forConsole($link->console_kind),
                            'labels' => array_filter(trans('gameservers.remote'), 'is_string'),
                            'quickLabels' => trans('gameservers.remote.quick_commands'),
                        ],
                    ],
                ];
            }

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
            'hasCurrent' => $current instanceof Event,
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
            $current?->is($event)
                ? route('servers.index', [], false)
                : route('servers.index', ['event' => $event->slug], false),
            301,
        );
    }
}
