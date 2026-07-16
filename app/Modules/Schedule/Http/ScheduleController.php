<?php

namespace App\Modules\Schedule\Http;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Support\ScheduleCalendar;
use App\Modules\Schedule\Support\ScheduleProjection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController extends Controller
{
    /**
     * The public programme for the event: the full chronological item list
     * plus a "now/next" pair the widget highlights without the client having
     * to re-derive interval-overlap rules itself.
     */
    public function show(Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $items = ScheduleProjection::itemsFor($event);
        $now = Carbon::now();

        return Inertia::render('Schedule/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'items' => ScheduleProjection::itemDtos($items),
            'now' => ScheduleProjection::currentItem($items, $now),
            'next' => ScheduleProjection::nextItem($items, $now),
            'labels' => trans('schedule.page'),
        ]);
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
