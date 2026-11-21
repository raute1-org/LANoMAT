<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Launcher dist directory
    |--------------------------------------------------------------------------
    |
    | Where the `/launcher` page and the Preflight `launcher_build` check read
    | `manifest.json` and the per-OS builds from. `scripts/ops/build-launcher.sh`
    | writes them; compose mounts `./clients-dist` read-only into the app
    | container, so the default works for the normal deployment. `LAUNCHER_DIST_PATH`
    | only exists for a host that keeps the builds elsewhere.
    |
    */

    'dist_path' => env('LAUNCHER_DIST_PATH', base_path('clients-dist/launcher')),

    /*
    |--------------------------------------------------------------------------
    | First lineup
    |--------------------------------------------------------------------------
    |
    | Comma-separated recipe slugs the launcher, the web game library and the
    | MCP catalogue show. Everything else stays in the database (admin, sync,
    | direct recipe APIs) but is hidden from the player-facing read paths.
    |
    | The default is the tested first lineup. An empty value means "show every
    | visible recipe" and keeps older installations working unchanged.
    |
    */

    'lineup' => env('LAUNCHER_LINEUP', 'unreal-tournament-2004,need-for-speed-underground-2,need-for-speed-underground-2-browser'),

];
