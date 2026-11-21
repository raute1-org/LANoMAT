<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Registration\Actions\RegisterForEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('event_register')]
#[Description('Register the token owner for an event. Ticket types are the event\'s own (usually "participant"); the action enforces availability, capacity and the registration window.')]
class EventRegisterTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Event slug.')->required(),
            'ticket_type' => $schema->string()->description('Ticket type key; the event default is "standard".')->default('standard'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $register = app(RegisterForEvent::class);
        $event = $this->eventFromSlug((string) $request->get('slug'));
        $tickets = RegisterForEvent::allowedTickets($event);
        $ticketType = (string) ($request->get('ticket_type') ?: ($tickets[0] ?? 'standard'));

        $registration = $register->handle($event, $this->user(), $ticketType);

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
