<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\GameServers\Actions\ReloadMap;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use App\Modules\GameServers\Support\Cs2\GunGameConfig;
use App\Modules\GameServers\Support\ServerListProjection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PermanentServerPageController extends Controller
{
    /**
     * The permanent servers (all year, independent of any LAN): visible to every logged-in
     * user at all times; there is deliberately no public page for logged-out visitors.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewPermanent', ServerLink::class);

        $viewer = $request->user();
        $manageable = false;

        // `canManage` is decided here, per server, by the same policy the Actions enforce.
        // The admin props (map list, current map) exist only for those who may manage.
        $servers = ServerListProjection::permanentLinks()->map(function (ServerLink $link) use ($viewer, &$manageable): array {
            $dto = ServerListProjection::permanentDto($link);
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

        return Inertia::render('Servers/Permanent', [
            'servers' => $servers,
            'adminMaps' => $manageable ? Cs2Settings::MAPS : null,
            'labels' => trans('gameservers.permanent.page') + trans('gameservers.page'),
        ]);
    }
}
