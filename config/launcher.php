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

];
