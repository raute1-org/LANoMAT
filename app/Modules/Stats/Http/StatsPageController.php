<?php

declare(strict_types=1);

namespace App\Modules\Stats\Http;

use App\Http\Controllers\Controller;
use App\Modules\Stats\Support\LeaderboardQuery;
use Inertia\Inertia;
use Inertia\Response;

class StatsPageController extends Controller
{
    /**
     * The public cross-event leaderboard — same "public like seating/
     * tournaments/schedule/servers" visibility rule as the rest of the
     * participant UI, but not scoped to a single event: it aggregates
     * across every tournament (see LeaderboardQuery).
     */
    public function leaderboard(): Response
    {
        return Inertia::render('Stats/Leaderboard', [
            'rows' => LeaderboardQuery::topEntrants(),
            'labels' => trans('stats.page'),
            'badgeLabels' => trans('stats.badges'),
        ]);
    }
}
