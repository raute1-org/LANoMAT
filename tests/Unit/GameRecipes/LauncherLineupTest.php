<?php

declare(strict_types=1);

use App\Modules\GameRecipes\Support\LauncherLineup;
use Illuminate\Support\Facades\Config;

/**
 * The one lineup rule, for a caller that already holds the row: the server
 * install block filters its catalogue in PHP with `allowsSlug()`, so a recipe
 * the config names (however it is spelled in the env value) and a recipe the
 * orga switched on in the panel are both on the lineup there.
 */
it('decides one lineup rule for a slug and its own panel flag', function () {
    Config::set('launcher.lineup', 'unreal-tournament-2004, nfsu2');

    $lineup = new LauncherLineup;

    expect($lineup->allowsSlug('unreal-tournament-2004', false))->toBeTrue()
        // The config is the only source for an unflagged recipe.
        ->and($lineup->allowsSlug('tiny-racer', false))->toBeFalse()
        // The panel flag adds to a curated lineup.
        ->and($lineup->allowsSlug('tiny-racer', true))->toBeTrue()
        // Case does not matter: the comparison is on the lowercased slug.
        ->and($lineup->allowsSlug('NFSU2', false))->toBeTrue();
});

it('leaves an empty lineup unrestricted for every row', function () {
    Config::set('launcher.lineup', '');

    $lineup = new LauncherLineup;

    expect($lineup->allowsSlug('tiny-racer', false))->toBeTrue()
        ->and($lineup->allowsSlug('anything-at-all', false))->toBeTrue();
});
