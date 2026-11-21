<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Catering\Actions\CreateFoodOrderDraft;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Events\Models\Event;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('catering_round_create')]
#[Description('Orga/admin: creates a food round of an event for a restaurant (slug from restaurants_list) as a DRAFT. It never opens the round: opening is a separate human step in the admin panel. Title and payment default from the restaurant (payment_method cash or paypal; a paypal link must be https).')]
#[IsIdempotent]
class CateringRoundCreateTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Catering);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Event slug.')->required(),
            'restaurant' => $schema->string()->description('Restaurant slug from restaurants_list.')->required(),
            'title' => $schema->string()->description('Round title, defaults to the restaurant name.'),
            'opens_at' => $schema->string()->description('ISO 8601 date-time when ordering starts.')->required(),
            'closes_at' => $schema->string()->description('ISO 8601 date-time of the order deadline.')->required(),
            'payment_method' => $schema->string()->enum(['cash', 'paypal'])->description('Defaults to the restaurant default, else cash.')->required(),
            'payment_link' => $schema->string()->description('https payment link, only with payment_method=paypal; defaults to the restaurant default link.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $user = $this->user();

        // Decided before anything is looked up, so a participant learns nothing.
        Gate::forUser($user)->authorize('create', FoodOrder::class);

        $data = $request->validate([
            'event_slug' => ['required', 'string', 'max:255'],
            'restaurant' => ['required', 'string', 'max:255'],
            'opens_at' => ['required', 'string'],
            'closes_at' => ['required', 'string'],
            'payment_method' => ['required', 'string'],
        ]);

        $event = Event::query()->where('slug', $data['event_slug'])->first();
        abort_unless($event instanceof Event, 404, 'Event not found.');

        $restaurant = Restaurant::query()->where('slug', $data['restaurant'])->first();
        abort_unless($restaurant instanceof Restaurant, 404, 'Restaurant not found.');

        $order = app(CreateFoodOrderDraft::class)->handle($user, $event, $restaurant, array_intersect_key(
            $request->all(),
            array_flip(['title', 'opens_at', 'closes_at', 'payment_method', 'payment_link']),
        ));

        return $this->structured([
            'order' => [
                'id' => $order->id,
                'title' => $order->title,
                'status' => $order->status->value,
                'event_slug' => $event->slug,
                'restaurant' => $restaurant->slug,
                'opens_at' => $order->opens_at?->toIso8601String(),
                'closes_at' => $order->closes_at?->toIso8601String(),
                'payment_method' => $order->payment_method->value,
            ],
        ]);
    }
}
