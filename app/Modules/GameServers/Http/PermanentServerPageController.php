<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Http;

use App\Http\Controllers\Controller;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PermanentServerPageController extends Controller
{
    /**
     * The permanent servers (all year, independent of any LAN): visible to every logged-in
     * user at all times; there is deliberately no public page for logged-out visitors.
     */
    public function index(): Response
    {
        Gate::authorize('viewPermanent', ServerLink::class);

        return Inertia::render('Servers/Permanent', [
            'servers' => ServerListProjection::forPermanent(),
            'labels' => trans('gameservers.permanent.page') + trans('gameservers.page'),
        ]);
    }
}
