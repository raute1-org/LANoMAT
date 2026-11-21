<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Http;

use App\Http\Controllers\Controller;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The global server browser at `/servers/all`: one place that answers "which servers are
 * running?" across every game, instead of one page per event. It aggregates the event
 * server lists of all publicly visible, non-archived events and the permanent servers
 * (see {@see ServerListProjection::forGlobalBrowser()}); the event server page and the
 * permanent page keep their own detail/admin views.
 *
 * Visibility mirrors those pages: login required (same rule as the event server list),
 * no draft events, no PII — only the public server data (address, map, players, join
 * links) that those lists already show. `game`/`q` filter server-side so a filtered
 * view is a real, linkable URL and the filter is testable.
 */
class GlobalServerPageController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewPermanent', ServerLink::class);

        $all = ServerListProjection::forGlobalBrowser(withPlayerList: $request->user() !== null);

        /** @var list<string> $games */
        $games = [];
        foreach ($all as $row) {
            $name = $row['game'] ?? null;

            if (is_string($name) && $name !== '' && ! in_array($name, $games, true)) {
                $games[] = $name;
            }
        }
        sort($games);

        // An unknown game simply means "all games"; a blank search is no filter.
        $game = $request->query('game');
        $game = is_string($game) ? $game : '';
        $game = in_array($game, $games, true) ? $game : '';

        $query = $request->query('q');
        $search = is_string($query) ? trim($query) : '';
        $needle = Str::lower($search);

        $servers = array_values(array_filter(
            $all,
            static function (array $row) use ($game, $needle): bool {
                if ($game !== '' && ($row['game'] ?? null) !== $game) {
                    return false;
                }

                return $needle === '' || Str::contains(ServerListProjection::searchText($row), $needle);
            },
        ));

        return Inertia::render('Servers/Global', [
            'servers' => $servers,
            'games' => $games,
            'filters' => [
                'game' => $game,
                'q' => $search,
                // Distinct empty states: no server running at all vs. nothing matching.
                'active' => $game !== '' || $search !== '',
            ],
            'labels' => trans('gameservers.browser') + trans('gameservers.page') + trans('gameservers.join_ui'),
        ]);
    }
}
