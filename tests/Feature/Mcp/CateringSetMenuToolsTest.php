<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Domain\MenuChoice;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Tools\CateringMenuGetTool;
use App\Modules\Mcp\Tools\CateringMenuImportTool;
use App\Modules\Mcp\Tools\CateringMenuPreviewTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setMenuToolItems(): array
{
    return [
        ['name' => 'Menü: Menü für 2 Personen', 'price_eur' => '24,90', 'key' => 'menu-2', 'choices' => [
            ['label' => 'Hauptgericht', 'count' => 2, 'categories' => ['Curry']],
            ['label' => 'Beilage', 'count' => 1, 'options' => ['reis'], 'allow_repeat' => false],
        ]],
        ['name' => 'Curry: Palak Mushroom', 'price_eur' => '10,90', 'key' => 'palak'],
        ['name' => 'Beilage: Reis', 'price_eur' => '3,00', 'key' => 'reis'],
    ];
}

it('previews, imports and returns set-menu choices in the same shape', function () {
    LanomatServer::actingAs(User::factory()->orga()->create());

    LanomatServer::tool(CateringMenuPreviewTool::class, ['mode' => 'create', 'name' => 'Curryhaus Beispiel', 'items' => setMenuToolItems()])
        ->assertOk()
        ->assertStructuredContentPath('valid', true)
        ->assertStructuredContentPath('menu.0.choices.0.label', 'Hauptgericht')
        ->assertStructuredContentPath('menu.0.choices.1.options.0', 'reis');
    expect(Restaurant::query()->count())->toBe(0);

    LanomatServer::tool(CateringMenuImportTool::class, ['mode' => 'create', 'name' => 'Curryhaus Beispiel', 'items' => setMenuToolItems()])
        ->assertOk()->assertStructuredContentPath('created', true);

    $restaurant = Restaurant::query()->firstOrFail();
    expect($restaurant->menu[0]->choices)->toHaveCount(2);

    LanomatServer::tool(CateringMenuGetTool::class, ['restaurant' => $restaurant->slug])->assertOk()
        ->assertStructuredContentPath('items.0.choices.0.label', 'Hauptgericht')
        ->assertStructuredContentPath('items.0.choices.0.count', 2)
        ->assertStructuredContentPath('items.0.choices.0.categories', ['Curry'])
        ->assertStructuredContentMissingPath('items.1.choices');

    // Replacing with the very same data is a no-op.
    LanomatServer::tool(CateringMenuImportTool::class, ['mode' => 'replace', 'restaurant' => $restaurant->slug, 'items' => setMenuToolItems()])
        ->assertOk()->assertStructuredContentPath('unchanged', true);
});

it('reports unknown keys and bad counts as errors and empty sources as warnings', function () {
    LanomatServer::actingAs(User::factory()->orga()->create());

    $bad = setMenuToolItems();
    $bad[0]['choices'][1]['options'] = ['ghost'];
    LanomatServer::tool(CateringMenuPreviewTool::class, ['mode' => 'create', 'name' => 'X', 'items' => $bad])
        ->assertOk()->assertStructuredContentPath('valid', false);

    $empty = setMenuToolItems();
    $empty[0]['choices'][0]['categories'] = ['Nichts'];
    LanomatServer::tool(CateringMenuPreviewTool::class, ['mode' => 'create', 'name' => 'X', 'items' => $empty])
        ->assertOk()->assertStructuredContentPath('valid', true);
});

it('keeps the set-menu groups when a replace import omits them', function () {
    LanomatServer::actingAs(User::factory()->orga()->create());
    $restaurant = Restaurant::factory()->withMenu([
        new MenuOption('menu-2', 'Menü 2', 2490, null, null, [new MenuChoice('Haupt', 1, options: ['palak'])]),
        new MenuOption('palak', 'Palak', 1090),
    ])->create();

    LanomatServer::tool(CateringMenuImportTool::class, ['mode' => 'replace', 'restaurant' => $restaurant->slug, 'items' => [
        ['key' => 'menu-2', 'name' => 'Menü 2', 'price_cents' => 2590],
        ['key' => 'palak', 'name' => 'Palak', 'price_cents' => 1090],
    ]])->assertOk();

    $menu = $restaurant->fresh()->menu;
    expect($menu[0]->priceCents)->toBe(2590)->and($menu[0]->choices)->toHaveCount(1);
});

it('merges a new set menu and keeps its references valid', function () {
    LanomatServer::actingAs(User::factory()->orga()->create());
    $restaurant = Restaurant::factory()->withMenu([new MenuOption('palak', 'Curry: Palak', 1090)])->create();

    LanomatServer::tool(CateringMenuImportTool::class, ['mode' => 'merge', 'restaurant' => $restaurant->slug, 'items' => [
        ['key' => 'box', 'name' => 'Box', 'price_cents' => 2000, 'choices' => [['label' => 'Haupt', 'count' => 1, 'categories' => ['Curry']]]],
    ]])->assertOk();

    expect(collect($restaurant->fresh()->menu)->firstWhere('key', 'box')->choices)->toHaveCount(1);
});
