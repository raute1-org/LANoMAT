<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Catering\Models\FoodOrder;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('catering_menu')]
#[Description('Open and scheduled food orders of one event with their menu options and prices (cents).')]
#[IsReadOnly]
class CateringMenuTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Catering);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Event slug.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $event = $this->eventFromSlug((string) $request->get('event_slug'));

        $orders = FoodOrder::query()
            ->where('event_id', $event->id)
            ->orderBy('opens_at')
            ->get();

        return $this->structured([
            'orders' => $orders->map(static fn (FoodOrder $order): array => [
                'id' => $order->id,
                'title' => $order->title,
                'status' => $order->status->value,
                'opens_at' => $order->opens_at?->toIso8601String(),
                'closes_at' => $order->closes_at?->toIso8601String(),
                'is_open_now' => $order->isOpenNow(),
                'menu' => array_map(
                    static fn ($option): array => $option->toArray(),
                    $order->menu,
                ),
            ])->all(),
        ]);
    }
}
