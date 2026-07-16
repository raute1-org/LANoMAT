<?php

namespace App\Modules\Schedule\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Actions\FavoriteScheduleItem;
use App\Modules\Schedule\Actions\UnfavoriteScheduleItem;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Models\ScheduleItemFavorite;
use App\Modules\Schedule\Support\ScheduleCalendar;
use App\Modules\Schedule\Support\ScheduleProjection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    /**
     * The public programme for the event: the full chronological item list
     * plus a "now/next" pair the widget highlights without the client having
     * to re-derive interval-overlap rules itself.
     */
    public function show(Request $request, Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $items = ScheduleProjection::itemsFor($event);
        $now = Carbon::now();

        $user = $request->user();
        $favoriteItemIds = $user === null
            ? collect()
            : ScheduleItemFavorite::query()
                ->where('user_id', $user->id)
                ->whereIn('schedule_item_id', $items->pluck('id'))
                ->pluck('schedule_item_id');

        return Inertia::render('Schedule/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'items' => ScheduleProjection::itemDtos($items, $favoriteItemIds),
            'now' => ScheduleProjection::currentItem($items, $now, $favoriteItemIds),
            'next' => ScheduleProjection::nextItem($items, $now, $favoriteItemIds),
            'labels' => trans('schedule.page'),
        ]);
    }

    /**
     * Favorite a schedule item for the authenticated user. Any authenticated
     * user may favorite any (publicly visible) item — see
     * `ScheduleItemPolicy::favorite()`.
     */
    public function favorite(Request $request, ScheduleItem $item, FavoriteScheduleItem $action): RedirectResponse
    {
        $this->authorize('favorite', $item);

        $action->handle($item, $this->authUser($request));

        return back();
    }

    /**
     * Unfavorite a schedule item — only the user's own favorite may be
     * removed (see `ScheduleItemPolicy::unfavorite()`); there is no
     * client-supplied favorite ID to trust here, the action is scoped to
     * the acting user server-side.
     */
    public function unfavorite(Request $request, ScheduleItem $item, UnfavoriteScheduleItem $action): RedirectResponse
    {
        $this->authorize('unfavorite', $item);

        $action->handle($item, $this->authUser($request));

        return back();
    }

    /**
     * ICS export of the public programme, one VEVENT per schedule item.
     */
    public function ics(Event $event): HttpResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $ics = ScheduleCalendar::for($event);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="schedule.ics"',
        ]);
    }
}
