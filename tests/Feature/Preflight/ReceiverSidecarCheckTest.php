<?php

declare(strict_types=1);

use App\Modules\Preflight\Checks\ReceiverSidecarCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.room_audio.token' => 'tok',
        'services.room_audio.rest_url' => 'https://denon-admin.test',
    ]);
});

it('has the room_audio key', function () {
    expect(app(ReceiverSidecarCheck::class)->key())->toBe('room_audio');
});

it('skips the receiver check when no token is configured', function () {
    config(['services.room_audio.token' => null]);

    expect(app(ReceiverSidecarCheck::class)->run()->status->value)->toBe('skipped');
});

it('reports down when the sidecar is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(app(ReceiverSidecarCheck::class)->run()->status->value)->toBe('down');
});

it('reports down when the sidecar answers with a server error', function () {
    Http::fake(['denon-admin.test/*' => Http::response('', 500)]);

    expect(app(ReceiverSidecarCheck::class)->run()->status->value)->toBe('down');
});

it('reports warn when the sidecar is up but the receiver is not connected', function () {
    Http::fake([
        'denon-admin.test/healthz' => Http::response(['status' => 'ok', 'connected' => false], 200),
        'denon-admin.test/state' => Http::response(['connected' => false], 200),
    ]);

    $result = app(ReceiverSidecarCheck::class)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->message)->toBe(__('preflight.messages.receiver_offline'));
});

it('reports ok when the sidecar reports the receiver connected', function () {
    Http::fake([
        'denon-admin.test/healthz' => Http::response(['status' => 'ok', 'connected' => true], 200),
        'denon-admin.test/state' => Http::response(['connected' => true], 200),
    ]);

    expect(app(ReceiverSidecarCheck::class)->run()->status->value)->toBe('ok');
});

it('reports down with a token message when /state answers 401 or 503', function (int $status) {
    Http::fake([
        'denon-admin.test/healthz' => Http::response(['status' => 'ok', 'connected' => true], 200),
        'denon-admin.test/state' => Http::response(['detail' => 'x'], $status),
    ]);

    $result = app(ReceiverSidecarCheck::class)->run();

    expect($result->status->value)->toBe('down')
        ->and($result->message)->toBe(__('preflight.messages.sidecar_token'));

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/state')
        && $request->hasHeader('Authorization', 'Bearer tok'));
})->with([401, 503]);

it('reports down when /state fails otherwise', function () {
    Http::fake([
        'denon-admin.test/healthz' => Http::response(['status' => 'ok', 'connected' => true], 200),
        'denon-admin.test/state' => Http::response('', 500),
    ]);

    expect(app(ReceiverSidecarCheck::class)->run()->status->value)->toBe('down');
});

it('reports down when the healthz payload is unreadable', function () {
    Http::fake(['denon-admin.test/healthz' => Http::response('not json', 200)]);

    $result = app(ReceiverSidecarCheck::class)->run();

    expect($result->status->value)->toBe('down')
        ->and($result->message)->toBe(__('preflight.messages.sidecar_unreadable'));
});

it('tolerates a trailing slash on the configured URL', function () {
    config(['services.room_audio.rest_url' => 'https://denon-admin.test/']);
    Http::fake([
        'denon-admin.test/healthz' => Http::response(['status' => 'ok', 'connected' => true], 200),
        'denon-admin.test/state' => Http::response(['connected' => true], 200),
    ]);

    expect(app(ReceiverSidecarCheck::class)->run()->status->value)->toBe('ok');
});
