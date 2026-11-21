<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Mcp\Support\SafeText;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('catering_menu')]
#[Description('Open and closed food orders of one event with their menu options, prices (cents) and optional descriptions. Drafts are only listed for orga/admin.')]
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

        // Same visibility as the participant page: drafts are not announced yet.
        $orders = FoodOrder::query()
            ->where('event_id', $event->id)
            ->when(! $this->user()->isOrga(), static fn ($query) => $query->where('status', '!=', FoodOrderStatus::Draft->value))
            ->orderBy('opens_at')
            ->orderBy('id')
            ->get();

        return $this->structured([
            'orders' => $orders->map(static fn (FoodOrder $order): array => [
                'id' => $order->id,
                'title' => SafeText::line($order->title, 255),
                'status' => $order->status->value,
                'opens_at' => $order->opens_at?->toIso8601String(),
                'closes_at' => $order->closes_at?->toIso8601String(),
                'is_open_now' => $order->isOpenNow(),
                'menu' => array_map(
                    static fn (MenuOption $option): array => [
                        'key' => $option->key,
                        'name' => SafeText::line($option->name, 255) ?? '',
                        'price_cents' => $option->priceCents,
                        ...($option->description === null ? [] : ['description' => SafeText::line($option->description, MenuOption::MAX_DESCRIPTION_LENGTH)]),
                    ],
                    $order->menu,
                ),
            ])->all(),
        ]);
    }
}
