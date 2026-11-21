<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use App\Modules\Mcp\Support\SafeText;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('catering_menu_get')]
#[Description('Orga/admin: the food rounds of an event including drafts, with the menu in exactly the shape catering_menu_preview/import accept (items[].key, name, price_cents) plus the number of ordered items. Use it for a round trip or before replace/merge.')]
#[IsReadOnly]
class CateringMenuGetTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Catering);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Event slug.')->required(),
            'order_id' => $schema->integer()->description('Only this round.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        Gate::forUser($this->user())->authorize('create', FoodOrder::class);

        $event = Event::query()->where('slug', (string) $request->get('event_slug'))->first();
        abort_unless($event instanceof Event, 404, 'Event not found.');

        $orders = FoodOrder::query()
            ->where('event_id', $event->id)
            ->when($request->get('order_id') !== null, static fn ($query) => $query->whereKey((int) $request->get('order_id')))
            ->withCount('items')
            ->orderBy('id')
            ->get();

        return $this->structured([
            'orders' => $orders->map(static fn (FoodOrder $order): array => [
                'id' => $order->id,
                'title' => SafeText::line($order->title, 255),
                'status' => $order->status->value,
                'opens_at' => $order->opens_at?->toIso8601String(),
                'closes_at' => $order->closes_at?->toIso8601String(),
                'payment_method' => $order->payment_method->value,
                'payment_link' => $order->payment_link,
                'ordered_items' => (int) $order->items_count,
                'items' => array_map(static fn ($option): array => [
                    'key' => $option->key,
                    'name' => SafeText::line($option->name, 255) ?? '',
                    'price_cents' => $option->priceCents,
                ], $order->menu),
            ])->all(),
        ]);
    }
}
