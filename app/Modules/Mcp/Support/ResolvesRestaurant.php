<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Events\Models\Event;
use Laravel\Mcp\Request;

/**
 * Finds the restaurant a menu tool addresses: by `restaurant` (slug), or, for the deprecated
 * form, through the round `order_id` of an event (`event_slug`). Callers authorize first.
 */
trait ResolvesRestaurant
{
    protected function restaurantFrom(Request $request, bool $required): ?Restaurant
    {
        $slug = $request->get('restaurant');
        $orderId = $request->get('order_id');

        if (is_string($slug) && $slug !== '') {
            $restaurant = Restaurant::query()->where('slug', $slug)->first();
            abort_unless($restaurant instanceof Restaurant, 404, 'Restaurant not found.');

            return $restaurant;
        }

        if ($orderId !== null) {
            // Deprecated: resolve the restaurant of that round.
            $event = Event::query()->where('slug', (string) $request->get('event_slug'))->first();
            abort_unless($event instanceof Event, 404, 'Event not found.');

            $order = FoodOrder::query()->with('restaurant')->where('event_id', $event->id)->find((int) $orderId);
            abort_unless($order instanceof FoodOrder, 404, 'Food order not found.');
            abort_unless($order->restaurant instanceof Restaurant, 404, 'This round has no restaurant (legacy round).');

            return $order->restaurant;
        }

        abort_if($required, 422, 'Give restaurant (slug from restaurants_list).');

        return null;
    }
}
