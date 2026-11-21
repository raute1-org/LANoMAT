<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Catering\Actions\ImportRestaurantMenu;
use App\Modules\Catering\Enums\MenuImportMode;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Mcp\Support\ResolvesRestaurant;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Shared argument schema and plumbing of the two menu-import tools. The agent
 * reads the PDF/web page/photo itself and passes structured data; LANoMAT never
 * fetches URLs or parses documents. All rules live in the Catering module
 * ({@see ImportRestaurantMenu}); this class only maps arguments.
 */
abstract class CateringMenuWriteTool extends LanomatTool
{
    use ResolvesRestaurant;

    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Catering);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum(['create', 'replace', 'merge'])
                ->description('create = new restaurant with this menu (needs name); replace = swap the menu of the restaurant (dishes that were ordered must keep their keys); merge = append new dishes, existing ones untouched.')
                ->required(),
            'restaurant' => $schema->string()->description('Restaurant slug from restaurants_list (replace/merge).'),
            'name' => $schema->string()->description('Restaurant name (required for create), e.g. "Pizzeria Beispiel".'),
            'event_slug' => $schema->string()->description('DEPRECATED: only together with order_id, which resolves to the restaurant of that round. Use restaurant instead.'),
            'order_id' => $schema->integer()->description('DEPRECATED: round id; resolves to the restaurant of the round (replace/merge). Use restaurant instead.'),
            'items' => $schema->array()->items($schema->object([
                'name' => $schema->string()->description('Dish name as printed, max 255 characters. Put qualifiers such as "vegetarisch" or "groß" into the name; a category can be a prefix ("Pizza: Margherita").')->required(),
                'price_cents' => $schema->integer()->description('Price in whole cents (850 = 8,50 EUR). Give this OR price_eur.'),
                'price_eur' => $schema->string()->description('Price as text with at most two decimals, e.g. "8,50". Give this OR price_cents.'),
                'description' => $schema->string()->description('Optional short dish description as printed (ingredients, max 600 characters, plain text; longer text is shortened with a warning). merge only fills a description that is still empty; replace sets it; an omitted description never deletes an existing one. Photos cannot be imported here: they are uploaded in the admin panel or assigned with the artisan command catering:import-dish-media.'),
                'key' => $schema->string()->description('Optional stable id (a-z, 0-9, - _; max 64). Omit it to derive one from the name; keep the keys from catering_menu_get when round-tripping.'),
            ]))->description('The dishes, 1 to 300. Never invent a price or a dish that is not in the source.')->required(),
        ];
    }

    abstract protected function apply(): bool;

    protected function run(Request $request): Response|ResponseFactory
    {
        $user = $this->user();

        // Orga/admin only, decided before the restaurant is looked up so a
        // participant learns nothing about restaurants or draft rounds.
        Gate::forUser($user)->authorize('create', Restaurant::class);

        $data = $request->validate([
            'mode' => ['required', Rule::in(array_map(static fn (MenuImportMode $m): string => $m->value, MenuImportMode::cases()))],
            'items' => ['required', 'array'],
        ], [], [
            'mode' => 'mode',
            'items' => 'items',
        ]);

        $mode = MenuImportMode::from((string) $data['mode']);
        $restaurant = $mode === MenuImportMode::Create ? null : $this->restaurantFrom($request, false);
        $name = $request->get('name');

        $report = app(ImportRestaurantMenu::class)->handle(
            $user,
            $mode,
            $restaurant,
            (array) $data['items'],
            is_string($name) ? $name : null,
            apply: $this->apply(),
        );

        return $this->structured($report->toArray());
    }
}
