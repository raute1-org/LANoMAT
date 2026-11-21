<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Tournaments\Models\Tournament;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('event_get')]
#[Description('One event with its tournaments, schedule and the caller\'s registration. Use the slug from events_list.')]
#[IsReadOnly]
class EventGetTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Event slug.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $event = $this->eventFromSlug((string) $request->get('slug'));
        $user = $this->user();

        $registration = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->first();

        $tournaments = Tournament::query()
            ->where('event_id', $event->id)
            ->visibleTo($user)
            ->with('game:id,name,slug')
            ->orderBy('starts_at')
            ->get();

        $schedule = $this->on(Module::Schedule)
            ? ScheduleItem::query()
                ->where('event_id', $event->id)
                ->orderBy('starts_at')
                ->orderBy('sort')
                ->get()
            : collect();

        return $this->structured([
            'event' => [
                'slug' => $event->slug,
                'name' => $event->name,
                'status' => $event->status->value,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'location' => $event->location,
                'arrival_info' => $event->arrival_info,
                'max_participants' => $event->max_participants,
                'my_registration' => $registration === null ? null : [
                    'status' => $registration->status->value,
                    'ticket_type' => $registration->ticket_type,
                    'checked_in_at' => $registration->checked_in_at?->toIso8601String(),
                ],
            ],
            'tournaments' => $tournaments->map(static fn (Tournament $tournament): array => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'format' => $tournament->format->value,
                'status' => $tournament->status->value,
                'game' => $tournament->game?->name,
                'team_size' => $tournament->team_size,
                'max_entries' => $tournament->max_entries,
                'starts_at' => $tournament->starts_at->toIso8601String(),
                'checkin_opens_at' => $tournament->checkin_opens_at?->toIso8601String(),
                'checkin_closes_at' => $tournament->checkin_closes_at?->toIso8601String(),
            ])->all(),
            'schedule' => $schedule->map(static fn (ScheduleItem $item): array => [
                'id' => $item->id,
                'type' => $item->type->value,
                'title' => $item->title,
                'starts_at' => $item->starts_at->toIso8601String(),
                'ends_at' => $item->ends_at?->toIso8601String(),
                'location' => $item->location,
            ])->all(),
        ]);
    }
}
