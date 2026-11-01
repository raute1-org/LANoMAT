<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http\Features;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Native client: the events a participant can see (every non-draft event, like the browser) with
 * the caller's own registration state and the "current" event the client should preselect.
 */
class EventsApiController extends NativeFeatureController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $events = Event::query()->publiclyVisible()->orderByRaw('starts_at desc nulls last')->orderByDesc('id')->get();

        /** @var Collection<int, EventRegistration> $registrations */
        $registrations = EventRegistration::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->get()
            ->keyBy('event_id');

        $current = $this->current($events, $registrations);

        return response()->json([
            'current_event_slug' => $current?->slug,
            'events' => $events->map(fn (Event $event): array => [
                'id' => $event->id,
                'slug' => $event->slug,
                'name' => $event->name,
                'status' => $event->status->value,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'location' => $event->location,
                'current' => $current !== null && $current->is($event),
                'registration' => $this->registrationDto($registrations->get($event->id)),
            ])->values()->all(),
        ]);
    }

    /**
     * A live event wins (one the caller is registered for first, then the most recently started);
     * otherwise the nearest upcoming (announced or open for registration) event the caller is
     * registered for. No registration for an upcoming event means no current event.
     *
     * @param  Collection<int, Event>  $events
     * @param  Collection<int, EventRegistration>  $registrations
     */
    private function current(Collection $events, Collection $registrations): ?Event
    {
        $live = $events->where('status', EventStatus::Live)
            ->sortByDesc(fn (Event $e): array => [$registrations->has($e->id) ? 1 : 0, $e->starts_at?->getTimestamp() ?? 0])
            ->first();

        if ($live !== null) {
            return $live;
        }

        return $events
            ->filter(fn (Event $e): bool => in_array($e->status, [EventStatus::Announced, EventStatus::Registration], true) && $registrations->has($e->id))
            ->sortBy(fn (Event $e): int => $e->starts_at?->getTimestamp() ?? PHP_INT_MAX)
            ->first();
    }

    /**
     * @return array{status: string, ticket_type: string, paid: bool, checked_in: bool}|null
     */
    private function registrationDto(?EventRegistration $registration): ?array
    {
        return $registration === null ? null : [
            'status' => $registration->status->value,
            'ticket_type' => $registration->ticket_type,
            'paid' => $registration->paid_at !== null,
            'checked_in' => $registration->checked_in_at !== null,
        ];
    }
}
