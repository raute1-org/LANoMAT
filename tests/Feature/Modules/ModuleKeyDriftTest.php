<?php

use App\Support\Modules\Module;

it('keeps the TypeScript ModuleKey union in sync with the Module enum', function () {
    $source = file_get_contents(base_path('resources/js/types/index.ts'));

    preg_match('/export type ModuleKey\s*=(.*?);/s', $source, $m);
    preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $keys);

    $expected = array_map(fn (Module $mod) => $mod->key(), Module::cases());
    sort($expected);
    $actual = $keys[1];
    sort($actual);

    expect($actual)->toBe($expected);
});
