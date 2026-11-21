<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('event_register')]
#[Description('Register the token owner for an event. Ticket types are the event\'s own (usually "standard"); when omitted the event\'s first configured ticket type is used. The action enforces availability, capacity and the registration window.')]
class EventRegisterTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Event slug.')->required(),
            'ticket_type' => $schema->string()->description('Ticket type key from the event settings; omit for the event default.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $register = app(RegisterForEvent::class);
        $user = $this->user();
        $event = $this->eventFromSlug((string) $request->get('slug'));

        Gate::forUser($user)->authorize('create', [EventRegistration::class, $event]);

        $requested = $request->get('ticket_type');
        $ticketType = is_string($requested) && $requested !== ''
            ? $requested
            // The event's own first ticket type is the default; "standard" is
            // only the fallback when an event has no ticket configuration.
            : (RegisterForEvent::allowedTickets($event)[0] ?? 'standard');

        $registration = $register->handle($event, $user, $ticketType);

        return $this->structured([
            'registration' => [
                'event_slug' => $event->slug,
                'status' => $registration->status->value,
                'ticket_type' => $registration->ticket_type,
                'registered_at' => $registration->created_at?->toIso8601String(),
            ],
        ]);
    }
}
