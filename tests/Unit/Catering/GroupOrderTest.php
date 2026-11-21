<?php

use App\Models\User;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\GroupOrder;

function goItem(int $userId, string $userName, string $key, int $cents, string $note = '', bool $included = false, bool $paid = false): FoodOrderItem
{
    $user = new User(['name' => $userName]);
    $item = new FoodOrderItem;
    $item->forceFill([
        'user_id' => $userId,
        'selection' => $note === '' ? ['option_key' => $key] : ['option_key' => $key, 'note' => $note],
        'price_cents' => $cents,
        'included' => $included,
        'paid_at' => $paid ? now() : null,
    ]);
    $item->setRelation('user', $user);

    return $item;
}

function goDishes(): array
{
    return ['curry' => 'Lamm: Chicken Curry', 'tikka' => 'Lamm: Tikka', 'cola' => 'Getränke: Cola', 'misc' => 'Ohne Prefix'];
}

it('groups quantities and notes per dish in menu order', function () {
    $order = GroupOrder::build('Runde', goDishes(), [
        goItem(1, 'Anna', 'cola', 300),
        goItem(1, 'Anna', 'curry', 1250, 'mild'),
        goItem(2, 'Ben', 'curry', 1250, 'scharf'),
        goItem(3, 'Cem', 'tikka', 1100),
    ]);

    expect($order->positions)->toBe(4)
        ->and($order->totalCents)->toBe(300 + 1250 + 1250 + 1100)
        ->and(array_column($order->categories, 'label'))->toBe(['Lamm', 'Getränke'])
        ->and($order->categories[0]['lines'][0]['name'])->toBe('Chicken Curry')
        ->and($order->categories[0]['lines'][0]['quantity'])->toBe(2)
        ->and($order->categories[0]['lines'][0]['totalCents'])->toBe(2500)
        ->and($order->kitchenLine($order->categories[0]['lines'][0]))->toBe('2× Chicken Curry (1× mild, 1× scharf)');
});

it('counts items without a note when others have one', function () {
    $order = GroupOrder::build('Runde', goDishes(), [
        goItem(1, 'Anna', 'curry', 1000, 'mild'),
        goItem(2, 'Ben', 'curry', 1000, 'mild'),
        goItem(3, 'Cem', 'curry', 1000),
    ]);

    expect($order->kitchenLine($order->categories[0]['lines'][0]))->toBe('3× Chicken Curry (2× mild, 1× ohne Anmerkung)');
});

it('puts dishes without a category and unknown keys into Weitere at the end', function () {
    $order = GroupOrder::build('Runde', goDishes(), [
        goItem(1, 'Anna', 'misc', 500),
        goItem(1, 'Anna', 'gone', 700),
        goItem(1, 'Anna', 'cola', 300),
    ]);

    expect(array_column($order->categories, 'label'))->toBe(['Getränke', 'Weitere'])
        ->and(array_column($order->categories[1]['lines'], 'name'))->toBe(['Ohne Prefix', 'gone']);
});

it('splits a dish with differing price snapshots into separate lines', function () {
    $order = GroupOrder::build('Runde', goDishes(), [
        goItem(1, 'Anna', 'cola', 300),
        goItem(2, 'Ben', 'cola', 350),
    ]);

    expect($order->categories[0]['lines'])->toHaveCount(2)
        ->and($order->totalCents)->toBe(650);
});

it('builds the person list with paid, open and included states', function () {
    $order = GroupOrder::build('Runde', goDishes(), [
        goItem(2, 'Ben', 'cola', 300),
        goItem(2, 'Ben', 'cola', 300),
        goItem(1, 'anna', 'curry', 1250, paid: true),
        goItem(3, 'Cem', 'tikka', 1100, included: true),
    ]);

    expect(array_column($order->persons, 'name'))->toBe(['anna', 'Ben', 'Cem'])
        ->and($order->persons[0]['state'])->toBe('paid')
        ->and($order->persons[1]['state'])->toBe('open')
        ->and($order->persons[1]['dueCents'])->toBe(600)
        ->and($order->persons[1]['dishes'])->toBe(['2× Cola'])
        ->and($order->persons[2]['state'])->toBe('included')
        ->and($order->persons[2]['dueCents'])->toBe(0)
        ->and($order->dueCents)->toBe(600)
        ->and($order->paidCents)->toBe(1250)
        ->and($order->includedCents)->toBe(1100)
        ->and($order->totalCents)->toBe(600 + 1250 + 1100);
});

it('writes a plain kitchen text without guest names', function () {
    $order = GroupOrder::build("Pizza\nrunde", goDishes(), [
        goItem(1, 'Anna Secret', 'curry', 1250, "mild\n**x**"),
        goItem(2, 'Ben Hidden', 'cola', 305),
    ]);

    $text = $order->text();

    expect($text)->toContain("Pizza runde\n")
        ->and($text)->toContain('1× Chicken Curry (1× mild **x**) (12,50 €)')
        ->and($text)->toContain('1× Cola (3,05 €)')
        ->and($text)->toContain('Gesamt: 2 Positionen, 15,55 €')
        ->and($text)->not->toContain('Anna')
        ->and($text)->not->toContain('Ben');
});

it('handles an empty round', function () {
    $order = GroupOrder::build('Leer', goDishes(), []);

    expect($order->positions)->toBe(0)->and($order->categories)->toBe([])->and($order->persons)->toBe([])
        ->and($order->text())->toContain('Gesamt: 0 Positionen, 0,00 €');
});
