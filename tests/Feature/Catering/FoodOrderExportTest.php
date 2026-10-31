<?php

use App\Models\User;
use App\Modules\Catering\Actions\ExportFoodOrderItemsCsv;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\EditFoodOrder;
use App\Modules\Catering\Filament\Resources\FoodOrders\RelationManagers\ItemsRelationManager;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Anna buys two Pizza Salami (one already paid) and one Salat with a note that
 * starts with a formula character. Ben buys one Pizza Salami.
 */
function orderForExport(): FoodOrder
{
    $event = Event::factory()->create();
    $order = FoodOrder::factory()->for($event)->create([
        'title' => 'Freitag Pizza',
        'menu' => [
            new MenuOption(key: 'pizza_salami', name: 'Pizza Salami', priceCents: 950),
            new MenuOption(key: 'salad', name: 'Salat', priceCents: 450),
        ],
    ]);

    $anna = User::factory()->create(['name' => 'Anna Muster']);
    $ben = User::factory()->create(['name' => 'Ben']);

    FoodOrderItem::factory()->for($order, 'foodOrder')->for($anna)->create([
        'selection' => ['option_key' => 'pizza_salami', 'note' => 'ohne Zwiebeln'],
        'price_cents' => 950,
        'paid_at' => now(),
    ]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($anna)->create([
        'selection' => ['option_key' => 'pizza_salami', 'note' => null],
        'price_cents' => 950,
    ]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($anna)->create([
        'selection' => ['option_key' => 'salad', 'note' => '=1+1'],
        'price_cents' => 450,
    ]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($ben)->create([
        'selection' => ['option_key' => 'pizza_salami', 'note' => null],
        'price_cents' => 950,
    ]);

    return $order;
}

/** @return list<list<string|null>> */
function csvRows(string $csv): array
{
    $body = substr($csv, 3); // strip the UTF-8 BOM

    return array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_values(array_filter(explode("\r\n", $body), fn (string $line): bool => $line !== '')),
    );
}

/** The rendered content of the totals dialog (its modal is not part of the page HTML until opened). */
function totalsDialogHtml(FoodOrder $order): string
{
    $component = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditFoodOrder::class])
        ->mountTableAction('totals');

    return (string) $component->instance()->getMountedTableAction()?->getModalContent();
}

// F-R2B-15: CSV export for the kitchen and the cashier.

it('exports the order items as UTF-8 CSV with BOM, semicolons and decimal commas', function () {
    $csv = app(ExportFoodOrderItemsCsv::class)->handle(orderForExport());

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");

    $rows = csvRows($csv);

    expect($rows[0])->toBe(['Teilnehmer', 'Option', 'Anmerkung', 'Preis (EUR)', 'Bezahlt'])
        ->and($rows[1])->toBe(['Anna Muster', 'Pizza Salami', 'ohne Zwiebeln', '9,50', 'ja'])
        ->and($rows[2])->toBe(['Anna Muster', 'Pizza Salami', '', '9,50', 'nein']);
});

it('neutralises spreadsheet formulas in participant-written cells', function () {
    $csv = app(ExportFoodOrderItemsCsv::class)->handle(orderForExport());

    $rows = csvRows($csv);

    expect($rows[3][2])->toBe("'=1+1");
});

it('lets the orga download the export as a CSV file from the order', function () {
    $order = orderForExport();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditFoodOrder::class])
        ->callTableAction('export')
        ->assertFileDownloaded('freitag-pizza.csv');
});

it('authorizes the export through the FoodOrder policy for orga only', function () {
    $order = orderForExport();

    expect(Gate::forUser(User::factory()->orga()->create())->allows('export', $order))->toBeTrue()
        ->and(Gate::forUser(User::factory()->helper()->create())->denies('export', $order))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->denies('export', $order))->toBeTrue();
});

// Orga totals: per option counts and German number format.

it('shows counts per option and German amounts in the totals dialog', function () {
    $order = orderForExport();

    $this->actingAs(User::factory()->orga()->create());

    $html = totalsDialogHtml($order);

    expect($html)->toContain('3x Pizza Salami')
        ->toContain('1x Salat')
        ->toContain('33,00 €')
        ->toContain('Anzahl pro Option')
        ->not->toContain('33.00');
});

it('formats the totals dialog amounts with a decimal comma and a thousands point', function () {
    $order = FoodOrder::factory()->create([
        'menu' => [new MenuOption(key: 'buffet', name: 'Buffet', priceCents: 100000)],
    ]);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for(User::factory()->create(['name' => 'Cara']))->create([
        'selection' => ['option_key' => 'buffet', 'note' => null],
        'price_cents' => 100000,
    ]);

    $this->actingAs(User::factory()->orga()->create());

    expect(totalsDialogHtml($order))->toContain('1.000,00 €');
});
