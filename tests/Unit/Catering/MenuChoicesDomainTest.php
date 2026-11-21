<?php

declare(strict_types=1);

use App\Modules\Catering\Domain\MenuChoice;
use App\Modules\Catering\Domain\MenuChoices;
use App\Modules\Catering\Domain\MenuImport;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Exceptions\CateringException;

function setMenuFixture(?array $groups = null): array
{
    return [
        new MenuOption('menu-2', 'Menü: Menü für 2 Personen', 2490, null, null, $groups ?? [
            new MenuChoice('Hauptgericht', 2, categories: ['Hähnchen', 'Vegetarisch']),
            new MenuChoice('Beilage', 1, options: ['reis', 'naan'], allowRepeat: false),
        ]),
        new MenuOption('tikka', 'Hähnchen: Tikka Masala', 1190),
        new MenuOption('korma', 'Hähnchen: Korma', 1190),
        new MenuOption('palak', 'Vegetarisch: Palak Mushroom', 1090),
        new MenuOption('lamm', 'Lamm: Lamb Curry', 1390),
        new MenuOption('reis', 'Beilagen: Reis', 300),
        new MenuOption('naan', 'Beilagen: Naan', 250),
    ];
}

it('keeps the stored json of a dish without choices byte-identical', function () {
    $option = new MenuOption('a', 'A', 100);

    expect($option->toArray())->toBe(['key' => 'a', 'name' => 'A', 'price_cents' => 100])
        ->and(MenuOption::fromArray(['key' => 'a', 'name' => 'A', 'price_cents' => 100])->choices)->toBe([]);
});

it('round-trips choices through the stored shape and drops malformed stored groups', function () {
    $option = setMenuFixture()[0];
    $again = MenuOption::fromArray($option->toArray());

    expect($again->choices)->toEqual($option->choices)
        ->and(MenuOption::fromArray(['key' => 'a', 'name' => 'A', 'price_cents' => 1, 'choices' => [['label' => 'X', 'count' => 99], 'junk']])->choices)->toBe([]);
});

it('offers category members and fixed dishes but never set menus', function () {
    $menu = setMenuFixture();
    [$main, $side] = $menu[0]->choices;

    expect(array_map(fn ($o) => $o->key, MenuChoices::eligible($main, $menu, 'menu-2')))->toBe(['tikka', 'korma', 'palak'])
        ->and(array_map(fn ($o) => $o->key, MenuChoices::eligible($side, $menu, 'menu-2')))->toBe(['reis', 'naan']);
});

it('validates references strictly and warns about empty sources', function () {
    $broken = setMenuFixture([
        new MenuChoice('A', 1, options: ['menu-2']),
        new MenuChoice('B', 1, options: ['ghost']),
        new MenuChoice('A', 1, categories: ['Nichts']),
    ]);
    $result = MenuChoices::validateMenu($broken);

    expect($result['errors'])->toHaveCount(3)
        ->and(implode(' ', $result['errors']))->toContain('itself')->toContain('unknown dish key')->toContain('duplicate group label');

    $warn = MenuChoices::validateMenu(setMenuFixture([new MenuChoice('Leer', 1, categories: ['Nichts'])]));
    expect($warn['errors'])->toBe([])->and($warn['warnings'])->toHaveCount(1);

    expect(MenuChoices::validateMenu(setMenuFixture())['errors'])->toBe([]);
});

it('refuses another set menu as a pick', function () {
    $menu = setMenuFixture();
    $menu[] = new MenuOption('menu-4', 'Menü: Menü für 4', 4000, null, null, [new MenuChoice('X', 1, options: ['menu-2'])]);

    expect(MenuChoices::validateMenu($menu)['errors'][0])->toContain('set menu itself');
});

it('accepts a complete selection and snapshots names', function () {
    $menu = setMenuFixture();
    $picks = MenuChoices::resolveSelection($menu[0], $menu, [
        ['group' => 'Hauptgericht', 'option_key' => 'palak'],
        ['group' => 'hauptgericht', 'option_key' => 'palak'],
        ['group' => 'Beilage', 'option_key' => 'naan'],
    ]);

    expect($picks)->toBe([
        ['group' => 'Hauptgericht', 'option_key' => 'palak', 'name' => 'Vegetarisch: Palak Mushroom'],
        ['group' => 'Hauptgericht', 'option_key' => 'palak', 'name' => 'Vegetarisch: Palak Mushroom'],
        ['group' => 'Beilage', 'option_key' => 'naan', 'name' => 'Beilagen: Naan'],
    ]);
});

it('rejects incomplete, foreign, repeated-when-forbidden and unknown-group selections', function (array $raw, string $key) {
    $menu = setMenuFixture();

    try {
        MenuChoices::resolveSelection($menu[0], $menu, $raw);
        $this->fail('Expected CateringException.');
    } catch (CateringException $e) {
        expect($e->translationKey)->toBe($key);
    }
})->with([
    'nothing' => [[], 'catering.errors.choices_incomplete'],
    'too few' => [[['group' => 'Hauptgericht', 'option_key' => 'palak'], ['group' => 'Beilage', 'option_key' => 'reis']], 'catering.errors.choices_incomplete'],
    'too many' => [[['group' => 'Hauptgericht', 'option_key' => 'palak'], ['group' => 'Hauptgericht', 'option_key' => 'korma'], ['group' => 'Hauptgericht', 'option_key' => 'tikka'], ['group' => 'Beilage', 'option_key' => 'reis']], 'catering.errors.choices_incomplete'],
    'foreign key' => [[['group' => 'Hauptgericht', 'option_key' => 'lamm'], ['group' => 'Hauptgericht', 'option_key' => 'korma'], ['group' => 'Beilage', 'option_key' => 'reis']], 'catering.errors.choices_invalid'],
    'unknown group' => [[['group' => 'Dessert', 'option_key' => 'reis']], 'catering.errors.choices_invalid'],
    'malformed' => [['nonsense'], 'catering.errors.choices_invalid'],
]);

it('parses choices on import, resolves dish names to keys and reports problems', function () {
    $result = MenuImport::normalize([
        ['name' => 'Menü für 2', 'price_eur' => '24,90', 'key' => 'menu-2', 'choices' => [
            ['label' => 'Hauptgericht', 'count' => 2, 'categories' => ['Curry']],
            ['label' => 'Beilage', 'count' => '1', 'options' => ['Reis'], 'allow_repeat' => false],
        ]],
        ['name' => 'Curry: Tikka', 'price_eur' => '11,90'],
        ['name' => 'Reis', 'price_eur' => '3,00', 'key' => 'rice'],
    ]);

    expect($result->errors)->toBe([])
        ->and($result->menu[0]->choices)->toHaveCount(2)
        ->and($result->menu[0]->choices[1]->options)->toBe(['rice'])
        ->and(implode(' ', $result->warnings))->toContain('matched the dish name');

    $bad = MenuImport::normalize([
        ['name' => 'M', 'price_cents' => 100, 'choices' => [['label' => 'X', 'count' => 0, 'categories' => ['A'], 'options' => ['b']]]],
    ]);
    expect($bad->errors)->not->toBe([]);

    $tooMany = MenuImport::normalize([['name' => 'M', 'price_cents' => 100, 'choices' => array_fill(0, 6, ['label' => 'X', 'count' => 1, 'categories' => ['A']])]]);
    expect($tooMany->errors[0])->toContain('at most 5');

    $unknownKey = MenuImport::normalize([['name' => 'M', 'price_cents' => 100, 'choices' => [['label' => 'X', 'count' => 1, 'options' => ['nope']]]]]);
    expect(implode(' ', $unknownKey->errors))->toContain('unknown dish key');
});

it('imports a menu without choices exactly as before', function () {
    $result = MenuImport::normalize([['name' => 'A', 'price_cents' => 100]]);

    expect($result->errors)->toBe([])->and($result->menu[0]->choices)->toBe([]);
});
