<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Mcp\Support\ResolvesRestaurant;
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
#[Description('Orga/admin: the menu of one restaurant (restaurant = slug from restaurants_list) in exactly the shape catering_menu_preview/import accept (items[].key, name, price_cents, description), plus the number of ordered items per dish and in total. Use it for a round trip or before replace/merge. event_slug + order_id still work (deprecated) and resolve to the restaurant of that round.')]
#[IsReadOnly]
class CateringMenuGetTool extends LanomatTool
{
    use ResolvesRestaurant;

    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Catering);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $schema->string()->description('Restaurant slug from restaurants_list.'),
            'event_slug' => $schema->string()->description('DEPRECATED: only together with order_id.'),
            'order_id' => $schema->integer()->description('DEPRECATED: resolves to the restaurant of this round.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        Gate::forUser($this->user())->authorize('create', Restaurant::class);

        $restaurant = $this->restaurantFrom($request, true);
        abort_unless($restaurant instanceof Restaurant, 404, 'Restaurant not found.');

        $ordered = $restaurant->orderedCounts();

        return $this->structured([
            'restaurant' => [
                'slug' => $restaurant->slug,
                'name' => SafeText::line($restaurant->name, 255),
                'default_payment_method' => $restaurant->default_payment_method?->value,
                'default_payment_link' => $restaurant->default_payment_link,
                'rounds' => FoodOrder::query()->where('restaurant_id', $restaurant->id)->count(),
                'ordered_items' => array_sum($ordered),
            ],
            'items' => array_map(static fn (MenuOption $option): array => [
                'key' => $option->key,
                'name' => SafeText::line($option->name, 255) ?? '',
                'price_cents' => $option->priceCents,
                'ordered' => $ordered[$option->key] ?? 0,
                ...($option->description === null ? [] : ['description' => SafeText::line($option->description, MenuOption::MAX_DESCRIPTION_LENGTH)]),
                ...($option->image === null ? [] : ['has_image' => true]),
            ], $restaurant->menu),
        ]);
    }
}
