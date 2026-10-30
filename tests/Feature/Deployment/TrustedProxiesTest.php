<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Behind Traefik the app sees plain HTTP on the compose network. Without
 * trusted proxies, generated URLs (Discord/Steam OAuth redirects, asset
 * links) would be http:// and the browser would hit mixed content.
 */
beforeEach(function () {
    Route::get('/_test/proxy-probe', fn () => response()->json([
        'url' => url('/probe'),
        'secure' => request()->secure(),
    ]));
});

it('builds https URLs from X-Forwarded-* headers sent by a trusted proxy', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'lan.example.test',
            'X-Forwarded-Port' => '443',
        ])
        ->getJson('/_test/proxy-probe')
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://lan.example.test/probe',
            'secure' => true,
        ]);
});

it('forces https URLs in production even without proxy headers', function () {
    $this->app['env'] = 'production';

    $provider = new class($this->app) extends AppServiceProvider
    {
        public function applyUrlPolicy(): void
        {
            $this->configureUrls();
        }
    };
    $provider->applyUrlPolicy();

    expect(url('/probe'))->toStartWith('https://');
});

it('leaves URLs as http outside production when no proxy headers are sent', function () {
    $provider = new class($this->app) extends AppServiceProvider
    {
        public function applyUrlPolicy(): void
        {
            $this->configureUrls();
        }
    };
    $provider->applyUrlPolicy();

    expect(url('/probe'))->toStartWith('http://');
});
