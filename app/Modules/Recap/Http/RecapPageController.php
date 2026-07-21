<?php

declare(strict_types=1);

namespace App\Modules\Recap\Http;

use App\Http\Controllers\Controller;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Recap\Support\RecapProjection;
use Inertia\Inertia;
use Inertia\Response;

class RecapPageController extends Controller
{
    /**
     * The public post-LAN recap: headline activity counts, tournament
     * podiums, and top gallery photos — same public-visibility rule as the
     * rest of the participant UI (no auth required), but additionally gated
     * on the event having actually wrapped (`Finished`/`Archived`). A recap
     * for a still-running or not-yet-started event would be premature and
     * misleading, so this deliberately narrows beyond
     * `Event::isPubliclyVisible()`.
     */
    public function show(Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);
        abort_unless(in_array($event->status, [EventStatus::Finished, EventStatus::Archived], true), 404);

        return Inertia::render('Recap/Show', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'recap' => RecapProjection::forEvent($event)->toArray(),
            'labels' => trans('recap.page'),
        ]);
    }
}
