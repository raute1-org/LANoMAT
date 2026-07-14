<?php

namespace App\Modules\Events\Http;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventPageController
{
    public function home(CurrentEvent $current): Response
    {
        $event = $current->get();

        return $event === null
            ? $this->archive()
            : $this->renderShow($event);
    }

    public function show(Event $event): Response
    {
        if ($event->status === EventStatus::Draft) {
            throw new NotFoundHttpException;
        }

        return $this->renderShow($event);
    }

    public function archive(): Response
    {
        $events = Event::query()
            ->whereIn('status', [EventStatus::Finished->value, EventStatus::Archived->value])
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (Event $e) => $this->summary($e))
            ->all();

        return Inertia::render('Event/Index', [
            'events' => $events,
            'labels' => trans('events.page'),
        ]);
    }

    private function renderShow(Event $event): Response
    {
        return Inertia::render('Event/Show', [
            'event' => $this->summary($event),
            'labels' => trans('events.page'),
            'statusLabels' => trans('events.status'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Event $event): array
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
