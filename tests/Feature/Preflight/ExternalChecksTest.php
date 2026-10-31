<?php

use App\Modules\Preflight\Checks\DiscordApiCheck;
use App\Modules\Preflight\Checks\DiscordGatewaySidecarCheck;
use App\Modules\Preflight\Checks\LiveKitCheck;
use App\Modules\Preflight\Checks\MumbleSidecarCheck;
use App\Modules\Preflight\Checks\MusicAssistantCheck;
use App\Modules\Preflight\Checks\PelicanCheck;
use App\Modules\Preflight\Checks\TeamSpeakSidecarCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('skips the discord api check when no bot token is configured', function () {
    config(['services.discord.bot_token' => null]);
    expect(app(DiscordApiCheck::class)->run()->status->value)->toBe('skipped');
});

it('reports discord api ok on 200', function () {
    config(['services.discord.bot_token' => 'tok']);

    Http::fake(['discord.com/*' => Http::response(['username' => 'LANoMAT'], 200)]);
    expect(app(DiscordApiCheck::class)->run()->status->value)->toBe('ok');
});

it('reports discord api down on a non-2xx response', function () {
    config(['services.discord.bot_token' => 'tok']);

    Http::fake(['discord.com/*' => Http::response('', 500)]);
    expect(app(DiscordApiCheck::class)->run()->status->value)->toBe('down');
});

it('skips the discord gateway sidecar check when no health url is configured', function () {
    config(['services.discord.gateway_health_url' => null]);
    expect(app(DiscordGatewaySidecarCheck::class)->run()->status->value)->toBe('skipped');
});

it('reports discord gateway sidecar ok when reachable', function () {
    config(['services.discord.gateway_health_url' => 'https://gateway.test/health']);

    Http::fake(['gateway.test/*' => Http::response('', 200)]);
    expect(app(DiscordGatewaySidecarCheck::class)->run()->status->value)->toBe('ok');
});

it('reports discord gateway sidecar down on a connection exception', function () {
    config(['services.discord.gateway_health_url' => 'https://gateway.test/health']);

    Http::fake(fn () => throw new ConnectionException('refused'));
    expect(app(DiscordGatewaySidecarCheck::class)->run()->status->value)->toBe('down');
});

it('skips mumble when the voice provider is not enabled', function () {
    config(['services.voice.providers' => ['teamspeak']]);
    expect(app(MumbleSidecarCheck::class)->run()->status->value)->toBe('skipped');
});

it('reports mumble ok when enabled and reachable', function () {
    config([
        'services.voice.providers' => ['mumble'],
        'services.mumble.rest_url' => 'https://mumble.test',
    ]);

    Http::fake(['mumble.test' => Http::response('', 200)]);
    expect(app(MumbleSidecarCheck::class)->run()->status->value)->toBe('ok');
});

it('reports mumble down when enabled but unreachable', function () {
    config([
        'services.voice.providers' => ['mumble'],
        'services.mumble.rest_url' => 'https://mumble.test',
    ]);

    Http::fake(fn () => throw new ConnectionException('refused'));
    expect(app(MumbleSidecarCheck::class)->run()->status->value)->toBe('down');
});

it('skips teamspeak when the voice provider is not enabled', function () {
    config(['services.voice.providers' => ['mumble']]);
    expect(app(TeamSpeakSidecarCheck::class)->run()->status->value)->toBe('skipped');
});

it('reports teamspeak ok when enabled and reachable', function () {
    config([
        'services.voice.providers' => ['teamspeak'],
        'services.teamspeak.rest_url' => 'https://teamspeak.test',
    ]);

    Http::fake(['teamspeak.test' => Http::response('', 200)]);
    expect(app(TeamSpeakSidecarCheck::class)->run()->status->value)->toBe('ok');
});

it('reports teamspeak down when enabled but unreachable', function () {
    config([
        'services.voice.providers' => ['teamspeak'],
        'services.teamspeak.rest_url' => 'https://teamspeak.test',
    ]);

    Http::fake(fn () => throw new ConnectionException('refused'));
    expect(app(TeamSpeakSidecarCheck::class)->run()->status->value)->toBe('down');
});

it('skips pelican when panel url is unset', function () {
    config(['services.pelican.panel_url' => null]);
    expect(app(PelicanCheck::class)->run()->status->value)->toBe('skipped');
});

it('reports pelican ok when reachable', function () {
    config(['services.pelican.panel_url' => 'https://pelican.test']);

    Http::fake(['pelican.test' => Http::response('', 200)]);
    expect(app(PelicanCheck::class)->run()->status->value)->toBe('ok');
});

it('reports pelican down when unreachable', function () {
    config(['services.pelican.panel_url' => 'https://pelican.test']);

    Http::fake(fn () => throw new ConnectionException('refused'));
    expect(app(PelicanCheck::class)->run()->status->value)->toBe('down');
});

it('skips music assistant when no token is configured', function () {
    config(['services.music_assistant.token' => null]);
    expect(app(MusicAssistantCheck::class)->run()->status->value)->toBe('skipped');
});

it('reports music assistant ok when a token is configured and reachable', function () {
    config([
        'services.music_assistant.token' => 'tok',
        'services.music_assistant.base_url' => 'https://music-assistant.test',
    ]);

    Http::fake(['music-assistant.test' => Http::response('', 200)]);
    expect(app(MusicAssistantCheck::class)->run()->status->value)->toBe('ok');
});

it('reports music assistant down when configured but unreachable', function () {
    config([
        'services.music_assistant.token' => 'tok',
        'services.music_assistant.base_url' => 'https://music-assistant.test',
    ]);

    Http::fake(fn () => throw new ConnectionException('refused'));
    expect(app(MusicAssistantCheck::class)->run()->status->value)->toBe('down');
});

it('reports discord gateway sidecar down while the bot is not connected to Discord', function () {
    config(['services.discord.gateway_health_url' => 'https://gateway.test/healthz']);

    // The sidecar process answers, but with 503 until the Gateway connection is up.
    Http::fake(['gateway.test/*' => Http::response('connecting', 503)]);

    $result = app(DiscordGatewaySidecarCheck::class)->run();

    expect($result->status->value)->toBe('down');
    expect($result->message)->toContain('503');
});

it('skips the livekit check until web voice has an api key', function () {
    config(['webvoice.api_key' => null]);
    expect(app(LiveKitCheck::class)->run()->status->value)->toBe('skipped');
});

it('reports livekit ok when its http port answers and down when it does not', function () {
    config(['webvoice.api_key' => 'k', 'webvoice.api_url' => 'http://livekit.test:7880']);

    Http::fake(['livekit.test:7880*' => Http::response('OK', 200)]);
    expect(app(LiveKitCheck::class)->run()->status->value)->toBe('ok');

    Http::fake(fn () => throw new ConnectionException('refused'));
    expect(app(LiveKitCheck::class)->run()->status->value)->toBe('down');
});

it('counts a redirect as a sign of life without following it to a name the host cannot reach', function () {
    config(['services.pelican.panel_url' => 'http://panel.test:8080']);

    // The panel answers with a redirect to its public https name. That the host answers at all is
    // what a reachability probe wants; following the redirect would fail wherever the app cannot
    // reach that name (a container on the very host that serves it).
    Http::fake([
        'panel.test:8080*' => Http::response('', 302, ['Location' => 'https://panel-public.test/login']),
        'panel-public.test*' => fn () => throw new ConnectionException('unreachable from here'),
    ]);

    expect(app(PelicanCheck::class)->run()->status->value)->toBe('ok');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'panel-public.test'));
});
