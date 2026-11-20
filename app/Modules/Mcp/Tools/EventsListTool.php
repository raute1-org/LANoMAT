<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('events_list')]
#[Description('List the LANoMAT events the token can see (never drafts), with dates, status and the caller\'s own registration.')]
#[IsReadOnly]
class EventsListTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_finished' => $schema->boolean()
                ->description('Include finished and archived events (default true).')
                ->default(true),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $user = $this->user();
        $includeFinished = (bool) $request->get('include_finished', true);

        $events = Event::query()
            ->publiclyVisible()
            ->when(! $includeFinished, fn ($query) => $query->whereIn('status', ['announced', 'registration', 'live']))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        $registrations = EventRegistration::query()
            ->where('user_id', $user->id)
            ->whereIn('event_id', $events->pluck('id'))
            ->get()
            ->keyBy('event_id');

        return $this->structured([
            'events' => $events->map(function ($event) use ($registrations): array {
                $registration = $registrations->get($event->id);

                return [
                    'slug' => $event->slug,
                    'name' => $event->name,
                    'status' => $event->status->value,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'location' => $event->location,
                    'my_registration' => $registration === null ? null : [
                        'status' => $registration->status->value,
                        'ticket_type' => $registration->ticket_type,
                    ],
                ];
            })->all(),
        ]);
    }
}
