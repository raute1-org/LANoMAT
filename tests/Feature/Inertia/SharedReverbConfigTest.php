<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Regression (prod bug, 2026-07-23): the Reverb connection data must reach the
// browser at *runtime* — resolved from server config per request — not baked
// into the compiled bundle. Vite inlines import.meta.env.VITE_REVERB_* at BUILD
// time (the Docker build uses .env.example), so a real deploy .env could never
// change which Reverb host the frontend connects to. The values are exposed as
// a `window.__reverb` global inlined by app.blade.php (read in app.ts to
// configure Echo), so this asserts the rendered document carries them.
//
// (An earlier fix delivered these as an Inertia shared prop read from the
// `#app` data-page in app.ts, but that attribute is not reliably present at
// module-load in this Inertia v2 setup, so Echo was instantiated without a key
// and every realtime page rendered blank — hence the window-global approach.)
it('exposes reverb connection config to the browser at runtime', function () {
    config([
        'broadcasting.connections.reverb.key' => 'runtime-key',
        'broadcasting.connections.reverb.options.host' => 'ws.example.test',
        'broadcasting.connections.reverb.options.port' => 8443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('window.__reverb', false)
        ->assertSee('runtime-key', false)
        ->assertSee('ws.example.test', false);
});
