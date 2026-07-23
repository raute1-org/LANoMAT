<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;

// Regression (prod bug, 2026-07-22): the Reverb connection data must reach the
// browser at *runtime* as an Inertia shared prop, resolved from server config
// per request. Previously the client read import.meta.env.VITE_REVERB_*, which
// Vite inlines at BUILD time from whatever .env exists then (the Docker build
// uses .env.example) — so a real .env at deploy time could never change which
// Reverb host the frontend connects to. Sharing it server-side fixes that.
it('shares reverb connection config as a runtime prop', function () {
    config([
        'broadcasting.connections.reverb.key' => 'runtime-key',
        'broadcasting.connections.reverb.options.host' => 'ws.example.test',
        'broadcasting.connections.reverb.options.port' => 8443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $shared = (new HandleInertiaRequests)->share(Request::create('/'));

    expect($shared['reverb'])->toBe([
        'key' => 'runtime-key',
        'host' => 'ws.example.test',
        'port' => 8443,
        'scheme' => 'https',
    ]);
});
