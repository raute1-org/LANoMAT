<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recipe sources
    |--------------------------------------------------------------------------
    |
    | Where `lanomat:recipes:sync` reads from when no source is given. The
    | bundled recipes ship with the app; the NAS share is the live catalogue
    | the user maintains (smb://192.168.68.55/games-recipes in the LAN).
    | Credentials are optional: a guest-readable share needs none.
    |
    */

    'source' => env('RECIPES_SOURCE', base_path('resources/recipes')),

    'smb' => [
        'user' => env('RECIPES_SMB_USER'),
        'password' => env('RECIPES_SMB_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LAN file preference
    |--------------------------------------------------------------------------
    |
    | The installer downloads from the LANoMAT file host first. Only when a
    | required file has no LAN copy does it consider the recipe's external
    | URL, and then only with `--allow-external`.
    |
    */

    'prefer_lan' => (bool) env('RECIPES_PREFER_LAN', true),

];
