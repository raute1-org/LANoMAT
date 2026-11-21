<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

// The maintenance page is served by an always-on nginx that deploys never restart. These file-level
// guards keep the page offline-safe and the Traefik/compose wiring intact (no real Traefik in tests).

function maintenancePath(string $relative): string
{
    return dirname(__DIR__, 2).'/'.$relative;
}

it('ships a self-contained maintenance page that polls /up', function (): void {
    $html = (string) file_get_contents(maintenancePath('docker/maintenance/site/index.html'));

    expect($html)->toContain('lang="de"')
        ->toContain("fetch('/up'")
        ->toContain('http-equiv="refresh"')
        ->toContain('prefers-reduced-motion')
        ->toContain('role="status"')
        ->not->toMatch('/\b(?:src|href)\s*=\s*["\']https?:/i')
        ->not->toContain('@import')
        ->not->toMatch('/url\(\s*["\']?https?:/i')
        ->not->toMatch('/<link[^>]+rel=["\']stylesheet/i');
});

it('answers every request with 503, Retry-After and no-store', function (): void {
    $conf = (string) file_get_contents(maintenancePath('docker/maintenance/default.conf'));

    expect($conf)->toContain('return 503')
        ->toContain('error_page 503 /index.html')
        ->toContain('Retry-After 30')
        ->toContain('no-store')
        ->toContain('/healthz');
});

it('declares the maintenance service with a healthcheck, no published port and a catch-all router', function (): void {
    $compose = Yaml::parseFile(maintenancePath('compose.yml'));
    $service = $compose['services']['maintenance'] ?? null;

    expect($service)->not->toBeNull()
        ->and($service['profiles'])->toContain('prod')
        ->and($service['healthcheck']['test'])->not->toBeEmpty()
        ->and($service)->not->toHaveKey('ports')
        ->and($service['image'])->toMatch('/^nginx:\d+\.\d+-alpine$/')
        ->and((string) $service['labels']['traefik.http.routers.maintenance.priority'])->toBe('1');
});

it('wires the maintenance-errors middleware to the app router only for 502-504', function (): void {
    $dynamic = Yaml::parseFile(maintenancePath('docker/traefik/dynamic.yml'));
    $errors = $dynamic['http']['middlewares']['maintenance-errors']['errors'] ?? null;

    expect($errors['status'])->toBe(['502-504'])
        ->and($errors['service'])->toBe('maintenance@docker');

    $labels = Yaml::parseFile(maintenancePath('compose.yml'))['services']['app']['labels'];
    expect($labels['traefik.http.routers.app.middlewares'])->toContain('maintenance-errors@file');

    foreach (['app-gallery', 'app-files', 'app-upload'] as $router) {
        expect($labels["traefik.http.routers.{$router}.middlewares"])->not->toContain('maintenance-errors');
    }

    $reverb = Yaml::parseFile(maintenancePath('compose.yml'))['services']['reverb-prod']['labels'];
    expect(json_encode($reverb))->not->toContain('maintenance-errors');
});

it('keeps the maintenance service out of the deploy SERVICES list', function (): void {
    $deploy = (string) file_get_contents(maintenancePath('scripts/deploy/deploy.sh'));

    expect($deploy)->toMatch('/^SERVICES=\([^)]*\)$/m')
        ->and($deploy)->not->toMatch('/^SERVICES=\([^)]*maintenance/m')
        ->and($deploy)->toContain('--no-recreate --no-deps maintenance');
});
