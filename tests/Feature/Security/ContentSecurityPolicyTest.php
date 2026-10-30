<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cspNonceFromHeader(string $policy): ?string
{
    return preg_match("/'nonce-([^']+)'/", $policy, $m) === 1 ? $m[1] : null;
}

it('sends the policy as report-only by default and never enforces', function () {
    $response = $this->get('/login');

    $response->assertOk();
    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toBeString()->not->toBeEmpty();
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('switches to the enforcing header and turns off by config', function () {
    config(['security.csp.mode' => 'enforce']);
    $enforced = $this->get('/login');
    expect($enforced->headers->has('Content-Security-Policy'))->toBeTrue()
        ->and($enforced->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();

    config(['security.csp.mode' => 'off']);
    $off = $this->get('/login');
    expect($off->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($off->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('falls back to report-only for an unknown mode value', function () {
    config(['security.csp.mode' => 'banana']);

    $response = $this->get('/login');

    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('builds a strict app policy without unsafe-eval and with report endpoints', function () {
    config([
        'broadcasting.connections.reverb.options.host' => 'lan.example.org',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $policy = $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');

    expect($policy)
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain('connect-src')->toContain('wss://lan.example.org:443')
        ->toContain('img-src')->toContain('https:')->toContain('data:')
        ->toContain('form-action')->toContain('https://discord.com')
        ->toContain('report-uri /csp-report')
        ->toContain('report-to csp-endpoint')
        ->toContain("'wasm-unsafe-eval'")
        ->not->toContain("'unsafe-eval'")
        ->not->toContain("script-src 'self' 'unsafe-inline'");
});

it('uses ws:// for a plain-http reverb scheme', function () {
    config([
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8081,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);

    expect($this->get('/login')->headers->get('Content-Security-Policy-Report-Only'))->toContain('ws://localhost:8081');
});

it('announces the reporting endpoint', function () {
    expect($this->get('/login')->headers->get('Reporting-Endpoints'))->toBe('csp-endpoint="/csp-report"');
});

it('puts a fresh per-request nonce into the header and the inline scripts', function () {
    $first = $this->get('/login');
    $second = $this->get('/login');

    $nonce1 = cspNonceFromHeader($first->headers->get('Content-Security-Policy-Report-Only'));
    $nonce2 = cspNonceFromHeader($second->headers->get('Content-Security-Policy-Report-Only'));

    expect($nonce1)->not->toBeNull()->not->toBe($nonce2);
    expect($first->getContent())->toContain('nonce="'.$nonce1.'"');
    // Every executable inline script carries the nonce.
    preg_match_all('/<script(?![^>]*type="application\/json")(?![^>]*\ssrc=)[^>]*>/', $first->getContent(), $tags);
    foreach ($tags[0] as $tag) {
        expect($tag)->toContain('nonce="'.$nonce1.'"');
    }
});

it('gives the admin panel its own policy that allows unsafe-eval for Alpine', function () {
    $response = $this->actingAs(User::factory()->orga()->create())->get('/admin');

    $response->assertOk();
    $policy = $response->headers->get('Content-Security-Policy-Report-Only');

    expect($policy)->toContain("'unsafe-eval'")->toContain("style-src 'self' 'unsafe-inline'");
    expect($response->getContent())->toContain('nonce="'.cspNonceFromHeader($policy).'"');
});

it('is sent on authenticated participant pages too', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertHeader('Content-Security-Policy-Report-Only');
});
