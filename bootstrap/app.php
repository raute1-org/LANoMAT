<?php

use App\Http\Controllers\CspReportController;
use App\Http\Middleware\AddContentSecurityPolicy;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitRequestBodySize;
use App\Modules\Discord\Http\Middleware\VerifyDiscordSignature;
use App\Modules\Discord\Http\Middleware\VerifyGatewaySecret;
use App\Modules\Files\Http\Middleware\VerifyTusHookSecret;
use App\Modules\WebVoice\NativeClient\Http\AddServerVersionHeader;
use App\Modules\WebVoice\NativeClient\Http\AuthenticateVoiceClient;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // Native voice client API: bearer tokens, no session, no cookies, no CSRF (the `web`
            // group is deliberately not applied). Gated by the WebVoice module toggle.
            if (app(Modules::class)->routable(Module::WebVoice)) {
                Route::middleware(AddServerVersionHeader::class)->group(base_path('routes/voice-client.php'));
            }

            // CSP violation reports: browsers POST these on their own, so no
            // session, cookies or CSRF (the `web` group is deliberately not
            // applied); size-limited in the controller and throttled here.
            Route::post('/csp-report', CspReportController::class)
                ->middleware('throttle:csp-report')
                ->name('csp-report');
        },
    )
    ->withCommands([
        __DIR__.'/../app/Modules/Discord/Console',
        __DIR__.'/../app/Modules/Files/Console',
        __DIR__.'/../app/Modules/GameServers/Console',
        __DIR__.'/../app/Modules/Jukebox/Console',
        __DIR__.'/../app/Modules/Lfg/Console',
        __DIR__.'/../app/Modules/Preflight/Console',
        __DIR__.'/../app/Modules/Presence/Console',
        __DIR__.'/../app/Modules/Provisioning/Console',
        __DIR__.'/../app/Modules/Schedule/Console',
        __DIR__.'/../app/Modules/Tournaments/Console',
        __DIR__.'/../app/Modules/WebVoice/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // The native client's bearer check must run before the throttles (they key on the
        // resolved user, not the IP) and before implicit route-model binding (otherwise an
        // unauthenticated caller could tell existing ids from missing ones by 404 vs 401).
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateVoiceClient::class);

        // Traefik terminates TLS and forwards plain HTTP on the compose network.
        // Trust its X-Forwarded-* headers so generated URLs (OAuth redirects,
        // asset links) and request->secure() reflect the public https origin.
        // Safe because the app is only reachable through Traefik (see compose.yml:
        // published app port is bound to 127.0.0.1).
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(prepend: [LimitRequestBodySize::class]);

        $middleware->web(append: [
            AddContentSecurityPolicy::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'discord.signature' => VerifyDiscordSignature::class,
            'discord.gateway' => VerifyGatewaySecret::class,
            'files.tusd' => VerifyTusHookSecret::class,
        ]);

        // Discord signs the raw body itself (see VerifyDiscordSignature); it
        // never carries a Laravel CSRF token, so the route is exempted here.
        // The gateway ingress is called by the discord.js sidecar over the
        // compose network (no session, no CSRF token) and authenticates via
        // its own shared secret instead (see VerifyGatewaySecret). The CS2
        // telemetry webhook is likewise called by a game server (no session,
        // no CSRF token) and authenticates via its own per-server bearer
        // token instead (see MatchTelemetryController). The tusd upload hooks are
        // relayed by the file host's nginx with a shared secret (VerifyTusHookSecret).
        $middleware->validateCsrfTokens(except: ['api/discord/interactions', 'internal/discord/gateway', 'internal/files/tusd-hook', 'api/telemetry/cs2/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
