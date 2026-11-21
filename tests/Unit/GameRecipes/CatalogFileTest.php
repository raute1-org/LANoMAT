<?php

declare(strict_types=1);

use App\Modules\GameRecipes\Support\CatalogFile;

/**
 * The catalogue table is the validator's index of the recipe directory:
 * exactly one row per recipe, under its status, sorted by slug. These tests
 * pin the edit rules; the proposal action uses them on what it read from the
 * base branch.
 */
function catalogFixture(): string
{
    return <<<'MARKDOWN'
# Recipe catalog

Intro line that must survive untouched.

## Published

| Slug | Title | Platforms | Status | One line |
| ---- | ----- | --------- | ------ | -------- |
| `alpha-game` | Alpha | windows | published | First one. |
| `zulu-game` | Zulu | windows | published | Last one. |

## Draft

| Slug | Title | Platforms | Status | One line |
| ---- | ----- | --------- | ------ | -------- |
| `draft-game` | Draft | windows | draft | Waits for the LAN. |

## Archived

| Slug | Title | Platforms | Status | One line |
| ---- | ----- | --------- | ------ | -------- |
| `old-game` | Old | windows | archived | Gone. |
MARKDOWN;
}

function catalogRowFor(string $slug, string $title, string $status): string
{
    return "| `{$slug}` | {$title} | windows | {$status} | A shared game. |";
}

it('adds a row to the table of its status in slug order', function () {
    $out = (new CatalogFile)->withRow(catalogFixture(), 'middle-game', 'draft', catalogRowFor('middle-game', 'Middle', 'draft'));

    $lines = explode("\n", $out);
    $draftRows = array_values(array_filter(
        $lines,
        static fn (string $line): bool => str_contains($line, '`draft-game`') || str_contains($line, '`middle-game`'),
    ));

    expect($draftRows)->toHaveCount(2)
        ->and($draftRows[0])->toContain('`draft-game`')
        ->and($draftRows[1])->toContain('`middle-game`')
        ->and($out)->toContain(catalogRowFor('middle-game', 'Middle', 'draft'));
});

it('replaces an existing row of the same slug in place', function () {
    $out = (new CatalogFile)->withRow(catalogFixture(), 'draft-game', 'draft', catalogRowFor('draft-game', 'Draft renamed', 'draft'));

    expect(substr_count($out, '`draft-game`'))->toBe(1)
        ->and($out)->toContain(catalogRowFor('draft-game', 'Draft renamed', 'draft'))
        ->and($out)->not->toContain('Waits for the LAN.');
});

it('moves a row between the Draft and Published tables', function () {
    $out = (new CatalogFile)->withRow(catalogFixture(), 'draft-game', 'published', catalogRowFor('draft-game', 'Draft', 'published'));

    [$published, $draft] = explode('## Draft', $out, 2);

    expect($published)->toContain('`draft-game`')
        ->and($draft)->not->toContain('`draft-game`')
        ->and(substr_count($out, '`draft-game`'))->toBe(1)
        // Slug order inside Published: alpha, draft, zulu.
        ->and(strpos($out, '`alpha-game`'))->toBeLessThan(strpos($out, '`draft-game`'))
        ->and(strpos($out, '`draft-game`'))->toBeLessThan(strpos($out, '`zulu-game`'));
});

it('leaves every other line byte-identical', function () {
    $before = catalogFixture();
    $out = (new CatalogFile)->withRow($before, 'middle-game', 'draft', catalogRowFor('middle-game', 'Middle', 'draft'));

    $unchanged = array_values(array_filter(
        explode("\n", $before),
        static fn (string $line): bool => ! str_contains($line, '`middle-game`'),
    ));

    $outLines = explode("\n", $out);

    foreach ($unchanged as $line) {
        expect($outLines)->toContain($line);
    }

    expect($out)->toStartWith("# Recipe catalog\n\nIntro line that must survive untouched.\n\n## Published")
        ->and($out)->toContain("## Archived\n\n| Slug | Title | Platforms | Status | One line |")
        ->and($out)->toContain('| `old-game` | Old | windows | archived | Gone. |');
});

it('refuses an unknown status', function () {
    (new CatalogFile)->withRow(catalogFixture(), 'middle-game', 'retired', catalogRowFor('middle-game', 'Middle', 'draft'));
})->throws(DomainException::class, 'retired');

it('refuses a catalogue without the section', function () {
    $withoutDraft = preg_replace('/## Draft.*?(?=## Archived)/s', '', catalogFixture());

    expect($withoutDraft)->toBeString();

    (new CatalogFile)->withRow((string) $withoutDraft, 'middle-game', 'draft', catalogRowFor('middle-game', 'Middle', 'draft'));
})->throws(DomainException::class, 'draft');

it('refuses a section without a table', function () {
    $withoutTable = preg_replace('/## Draft\n\n\|.*?\n\n(?=## Archived)/s', "## Draft\n\nNo table here.\n\n", catalogFixture());

    expect($withoutTable)->toBeString();

    (new CatalogFile)->withRow((string) $withoutTable, 'middle-game', 'draft', catalogRowFor('middle-game', 'Middle', 'draft'));
})->throws(DomainException::class, 'no table');
