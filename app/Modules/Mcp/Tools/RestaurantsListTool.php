<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Catering\Models\Restaurant;
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

#[Name('restaurants_list')]
#[Description('Orga/admin: the restaurants and delivery services (event-spanning) with slug, name, number of dishes and rounds, and contact data. Use the slug with catering_menu_get/preview/import and catering_round_create.')]
#[IsReadOnly]
class RestaurantsListTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Catering);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        // Contact data and notes are orga data: the whole tool is orga/admin only.
        Gate::forUser($this->user())->authorize('viewAny', Restaurant::class);

        $restaurants = Restaurant::query()->withCount('foodOrders')->orderBy('name')->orderBy('id')->get();

        return $this->structured([
            'restaurants' => $restaurants->map(static fn (Restaurant $restaurant): array => [
                'slug' => $restaurant->slug,
                'name' => SafeText::line($restaurant->name, 255),
                'dish_count' => count($restaurant->menu),
                'rounds' => (int) $restaurant->food_orders_count,
                'phone' => $restaurant->phone,
                'address' => SafeText::line((string) $restaurant->address, 255),
                'website' => $restaurant->website,
                'order_url' => $restaurant->order_url,
                'default_payment_method' => $restaurant->default_payment_method?->value,
            ])->all(),
        ]);
    }
}
