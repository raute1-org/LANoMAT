<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Catering\Actions\ImportFoodOrderMenu;
use App\Modules\Catering\Enums\MenuImportMode;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
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
 * ({@see ImportFoodOrderMenu}); this class only maps arguments.
 */
abstract class CateringMenuWriteTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Catering);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Event slug.')->required(),
            'mode' => $schema->string()->enum(['create', 'replace', 'merge'])
                ->description('create = new draft round; replace = swap the menu of order_id (refused once it has orders); merge = append new dishes to order_id, existing ones untouched.')
                ->required(),
            'order_id' => $schema->integer()->description('Round id from catering_menu_get (replace/merge only).'),
            'items' => $schema->array()->items($schema->object([
                'name' => $schema->string()->description('Dish name as printed, max 255 characters. Put qualifiers such as "vegetarisch" or "groß" into the name; a category can be a prefix ("Pizza: Margherita").')->required(),
                'price_cents' => $schema->integer()->description('Price in whole cents (850 = 8,50 EUR). Give this OR price_eur.'),
                'price_eur' => $schema->string()->description('Price as text with at most two decimals, e.g. "8,50". Give this OR price_cents.'),
                'description' => $schema->string()->description('Optional short dish description as printed (ingredients, max 600 characters, plain text; longer text is shortened with a warning). merge only fills a description that is still empty; replace sets it; an omitted description never deletes an existing one. Photos cannot be imported here: they are uploaded in the admin panel or assigned with the artisan command catering:import-dish-media.'),
                'key' => $schema->string()->description('Optional stable id (a-z, 0-9, - _; max 64). Omit it to derive one from the name; keep the keys from catering_menu_get when round-tripping.'),
            ]))->description('The dishes, 1 to 300. Never invent a price or a dish that is not in the source.')->required(),
            'title' => $schema->string()->description('Round title (required for create; replace only when changing it), e.g. "Pizza Samstag".'),
            'opens_at' => $schema->string()->description('ISO 8601 date-time when ordering starts (create/replace).'),
            'closes_at' => $schema->string()->description('ISO 8601 date-time of the order deadline (create/replace).'),
            'payment_method' => $schema->string()->enum(['cash', 'paypal'])->description('Default cash (create/replace).'),
            'payment_link' => $schema->string()->description('https payment link, only with payment_method=paypal.'),
        ];
    }

    abstract protected function apply(): bool;

    protected function run(Request $request): Response|ResponseFactory
    {
        $user = $this->user();

        // Orga/admin only, decided before the event or round is looked up so a
        // participant learns nothing about unpublished events or draft rounds.
        Gate::forUser($user)->authorize('create', FoodOrder::class);

        $data = $request->validate([
            'event_slug' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::in(array_map(static fn (MenuImportMode $m): string => $m->value, MenuImportMode::cases()))],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'items' => ['required', 'array'],
        ], [], [
            'event_slug' => 'event_slug',
            'mode' => 'mode',
            'order_id' => 'order_id',
            'items' => 'items',
        ]);

        $event = Event::query()->where('slug', (string) $data['event_slug'])->first();
        abort_unless($event instanceof Event, 404, 'Event not found.');

        $order = null;

        if (isset($data['order_id'])) {
            $order = FoodOrder::query()->where('event_id', $event->id)->find((int) $data['order_id']);
            abort_unless($order instanceof FoodOrder, 404, 'Food order not found.');
        }

        $report = app(ImportFoodOrderMenu::class)->handle(
            $user,
            $event,
            MenuImportMode::from((string) $data['mode']),
            $order,
            (array) $data['items'],
            array_filter(
                array_intersect_key($request->all(), array_flip(['title', 'opens_at', 'closes_at', 'payment_method', 'payment_link'])),
                static fn (mixed $value): bool => $value !== null,
            ),
            apply: $this->apply(),
        );

        return $this->structured($report->toArray());
    }
}
