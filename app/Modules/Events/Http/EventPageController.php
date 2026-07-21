<?php

namespace App\Modules\Events\Http;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\News\Models\NewsPost;
use App\Modules\News\Support\NewsQuery;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Models\Poll;
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
        if (! $event->isPubliclyVisible()) {
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
            'news' => app(NewsQuery::class)->published(3)
                ->map(fn (NewsPost $post) => [
                    'id' => $post->id,
                    'title' => $post->title,
                    'body' => $post->body,
                    'publishedAt' => $post->published_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
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
            'arrivalInfo' => $event->arrival_info,
            'hype' => $this->hypeFor($event),
        ];
    }

    /**
     * Pre-LAN countdown/hype props: only meaningful before the event has
     * actually started, so it is limited to the Announced/Registration
     * window with a start date still in the future. Pure display data — no
     * write path — computed fresh on every page load.
     *
     * @return array<string, mixed>|null
     */
    private function hypeFor(Event $event): ?array
    {
        if (! in_array($event->status, [EventStatus::Announced, EventStatus::Registration], true)) {
            return null;
        }

        if ($event->starts_at === null || ! $event->starts_at->isFuture()) {
            return null;
        }

        $poll = Poll::query()
            ->where('event_id', $event->id)
            ->where('status', PollStatus::Open->value)
            ->orderByDesc('created_at')
            ->first();

        return [
            'startsAt' => $event->starts_at->toIso8601String(),
            'registrationCount' => $event->registrations()
                ->where('status', '!=', RegistrationStatus::Cancelled->value)
                ->count(),
            'activePoll' => $poll === null ? null : [
                'id' => $poll->id,
                'question' => $poll->question,
            ],
        ];
    }
}
