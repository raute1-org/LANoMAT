<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamJoinRequest;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Route authorization matrix
|--------------------------------------------------------------------------
|
| This suite inspects the *registered* routes (not a hand-written list), so a
| newly added route cannot ship unauthenticated or without an authorization
| expectation:
|
|   1. Every route is either authenticated (auth middleware), guest-only
|      (RedirectIfAuthenticated), or listed in PUBLIC_ROUTES with a reason.
|   2. Every authenticated route without a bound model or role middleware is
|      listed in SELF_SERVICE_ROUTES (acts only on the caller's own data).
|   3. Every authenticated route with a bound foreign model id must be
|      covered by an IDOR scenario below or listed in OPEN_OBJECT_ROUTES.
|   4. Guests are bounced from every authenticated route, role middleware is
|      enforced per role, event routes hide non-public events, and every
|      state-changing route sits behind CSRF protection.
*/

const PUBLIC_ROUTES = [
    'GET .well-known/passkey-endpoints' => 'passkey discovery document',
    'GET /' => 'public home page',
    'POST _boost/browser-logs' => 'laravel/boost dev tooling, only registered when the dev dependency is installed (never in the --no-dev image)',
    'POST api/discord/interactions' => 'Ed25519 signature middleware (VerifyDiscordSignature)',
    'POST api/telemetry/cs2/{serverLink}' => 'per-server telemetry token checked in the controller',
    'POST api/voice-client/device' => 'native voice client device flow step 1: unauthenticated by design, throttled per IP',
    'POST api/voice-client/token' => 'native voice client device flow step 3: the device code is the credential, throttled per IP and code',
    'GET api/voice-client/v1/me' => 'bearer middleware (AuthenticateVoiceClient), never the session',
    'GET api/voice-client/v1/rooms' => 'bearer middleware (AuthenticateVoiceClient); rooms from VisibleChannels',
    'POST api/voice-client/v1/rooms/{room}/token' => 'bearer middleware; WebVoiceChannelPolicy view+join, 404 for invisible rooms (tests/Feature/WebVoice/NativeClient)',
    'GET api/voice-client/v1/config' => 'bearer middleware (AuthenticateVoiceClient); no secrets',
    'GET api/voice-client/v1/events/{event}/now' => 'bearer middleware; own matches/votes/orders/notifications only, draft events 404 (tests/Feature/WebVoice/NativeClient/NowFeedTest)',
    'GET api/voice-client/v1/events/{event}/tournaments' => 'bearer middleware; same visibility as the web list, draft events 404',
    'GET api/voice-client/v1/events/{event}/my-matches' => 'bearer middleware; only matches of the caller\'s own or rostered entries',
    'GET api/voice-client/v1/events/{event}/servers' => 'bearer middleware; web server list narrowed to the caller\'s own match server (ServerListProjection::forEventViewedBy)',
    'GET api/voice-client/v1/servers' => 'bearer middleware; ServerLinkPolicy viewPermanent (every logged-in user), like the web page',
    'POST api/voice-client/v1/tournaments/{tournament}/checkin' => 'bearer middleware; acts on the caller\'s own entry, TournamentPolicy checkIn, hidden tournaments 404',
    'POST api/voice-client/v1/matches/{match}/report' => 'bearer middleware; TournamentPolicy report + own entry (404 for non-participants)',
    'POST api/voice-client/v1/matches/{match}/confirm' => 'bearer middleware; TournamentPolicy confirm (opponent only) + lock_version',
    'POST api/voice-client/v1/matches/{match}/dispute' => 'bearer middleware; TournamentPolicy dispute (opponent only)',
    'POST api/voice-client/v1/matches/{match}/report/withdraw' => 'bearer middleware; TournamentPolicy withdrawReport (reporting side only) + lock_version',
    // Native client event features: bearer middleware + the browser's Actions/Policies (tests/Feature/WebVoice/NativeClient/Features)
    'GET api/voice-client/v1/events' => 'bearer middleware; non-draft events plus the caller\'s own registration',
    'GET api/voice-client/v1/events/{event}/polls' => 'bearer middleware; draft events 404',
    'GET api/voice-client/v1/polls/{poll}' => 'bearer middleware; draft events 404, results as on the web page',
    'POST api/voice-client/v1/polls/{poll}/vote' => 'bearer middleware; PollPolicy vote + CastVote rules',
    'GET api/voice-client/v1/events/{event}/catering' => 'bearer middleware; own items only',
    'GET api/voice-client/v1/events/{event}/catering/mine' => 'bearer middleware; own items only',
    'POST api/voice-client/v1/food-orders/{foodOrder}/items' => 'bearer middleware; FoodOrderItemPolicy create + PlaceFoodOrderItem rules',
    'DELETE api/voice-client/v1/food-order-items/{foodOrderItem}' => 'bearer middleware; FoodOrderItemPolicy delete + CancelFoodOrderItem window',
    'GET api/voice-client/v1/events/{event}/files' => 'bearer middleware; approved + own pending files (VisibleFiles)',
    'GET api/voice-client/v1/events/{event}/files/quota' => 'bearer middleware; the caller\'s own usage',
    'POST api/voice-client/v1/events/{event}/files/uploads/ticket' => 'bearer middleware; SharedFilePolicy create + IssueUploadTicket rules',
    'GET api/voice-client/v1/files/{sharedFile}/download' => 'bearer middleware; SharedFilePolicy download, signed file-host URL',
    'DELETE api/voice-client/v1/files/{sharedFile}' => 'bearer middleware; SharedFilePolicy delete',
    'GET api/voice-client/v1/rooms/{room}/messages' => 'bearer middleware; WebVoiceChannelPolicy view (404 when unseen) + ChannelMessages (tests/Feature/WebVoice/NativeClient)',
    'POST api/voice-client/v1/rooms/{room}/messages' => 'bearer middleware; view (404) then PostMessage authorizes post, shared web-voice-chat throttle',
    'DELETE api/voice-client/v1/messages/{message}' => 'bearer middleware; view (404) then DeleteMessage authorizes moderate',
    'POST api/voice-client/v1/broadcasting/auth' => 'bearer middleware; channel allowlist + the routes/channels.php callbacks decide, 403 otherwise',
    'GET api/voice-client/v1/notifications' => 'bearer middleware; own notifications relation only',
    'GET api/voice-client/v1/notifications/unread-count' => 'bearer middleware; own notifications relation only',
    'POST api/voice-client/v1/notifications/read-all' => 'bearer middleware; own notifications relation only',
    'POST api/voice-client/v1/notifications/{notification}/read' => 'bearer middleware; scoped through the own notifications relation, foreign id is 404',
    'GET api/voice-client/v1/notification-preferences' => 'bearer middleware; the caller own preferences',
    'PUT api/voice-client/v1/notification-preferences' => 'bearer middleware; the caller own preferences, category allowlist',
    // Native client account block: bearer middleware + the browser's Actions/Policies (tests/Feature/WebVoice/NativeClient/Account)
    'GET api/voice-client/v1/profile' => 'bearer middleware; the caller own profile only, no role/discord_id',
    'PUT api/voice-client/v1/profile' => 'bearer middleware; the browser ProfileUpdateRequest rules on the caller own row',
    'PATCH api/voice-client/v1/profile' => 'bearer middleware; the browser ProfileUpdateRequest rules on the caller own row',
    'GET api/voice-client/v1/connections' => 'bearer middleware; the caller own linked accounts, never tokens',
    'POST api/voice-client/v1/connections/{provider}/link-url' => 'bearer middleware; single-use link token bound to the caller',
    'DELETE api/voice-client/v1/connections/{provider}' => 'bearer middleware; LinkedAccountPolicy delete on the caller own account',
    'GET api/voice-client/v1/friends' => 'bearer middleware; the caller own friends (display fields only)',
    'GET api/voice-client/v1/friends/requests' => 'bearer middleware; the caller own requests',
    'GET api/voice-client/v1/friends/suggestions' => 'bearer middleware; FriendSuggestions for the caller',
    'GET api/voice-client/v1/friends/blocked' => 'bearer middleware; the caller own blocks',
    'POST api/voice-client/v1/friends/requests' => 'bearer middleware; SendFriendRequest, never reveals a block against the caller',
    'POST api/voice-client/v1/friends/requests/{friendship}/accept' => 'bearer middleware; FriendshipPolicy respond (addressee only), strangers 404',
    'POST api/voice-client/v1/friends/requests/{friendship}/decline' => 'bearer middleware; FriendshipPolicy respond (addressee only), strangers 404',
    'DELETE api/voice-client/v1/friends/requests/{friendship}' => 'bearer middleware; FriendshipPolicy cancel (requester only), strangers 404',
    'DELETE api/voice-client/v1/friends/{user}' => 'bearer middleware; removes the caller own friendship with {user}',
    'POST api/voice-client/v1/users/{user}/block' => 'bearer middleware; the caller blocks {user}',
    'DELETE api/voice-client/v1/users/{user}/block' => 'bearer middleware; the caller unblocks {user}',
    'GET auth/discord/callback' => 'OAuth callback, state-verified by Socialite',
    'GET broadcasting/auth' => 'channel authorization callbacks decide per channel (routes/channels.php)',
    'POST csp-report' => 'browser CSP reports, throttled and size-limited',
    'POST dev/login/{role}' => 'local-only dev login, 404s outside APP_ENV=local',
    'GET discord/voice' => 'public no-PII voice occupancy projection',
    'GET filament/exports/{export}/download' => 'Filament action middleware authorizes the owner',
    'GET filament/imports/{import}/failed-rows/download' => 'Filament action middleware authorizes the owner',
    'GET gallery/public/photos/{eventPhoto}' => 'approved photos of publicly visible events only',
    'GET gallery/public/photos/{eventPhoto}/thumb' => 'approved photos of publicly visible events only',
    'POST internal/discord/gateway' => 'shared-secret middleware (VerifyGatewaySecret)',
    'POST internal/files/tusd-hook' => 'shared-secret middleware (VerifyTusHookSecret)',
    'GET overlay/match/{match}/scoreboard' => 'OBS overlay, public data only',
    'GET overlay/tournament/{tournament}/bracket' => 'OBS overlay, public data only',
    'GET screen/{event}' => 'public beamer infoscreen, no PII',
    'GET storage/{path}' => 'framework local-disk route, signed URLs only',
    'PUT storage/{path}' => 'framework local-disk route, signed URLs only',
    'GET teams' => 'public team directory',
    'GET teams/{team}' => 'public team page',
    'GET up' => 'health probe',
    'GET users/{user}' => 'public profile (non-PII projection)',
    'POST livewire-*/update' => 'Livewire endpoint, component-level authorization (Filament)',
    'POST livewire-*/upload-file' => 'Livewire upload, signed + throttled',
    'GET livewire-*' => 'Livewire static assets / preview-file (signed)',
];

/**
 * Authenticated routes that carry no foreign model id and no role middleware:
 * they only ever act on the authenticated user's own data.
 */
const SELF_SERVICE_ROUTES = [
    'GET checkin/{token}', 'POST checkin/{token}', 'GET dashboard', 'GET events',
    'GET friends', 'POST friends/request', 'GET stats/leaderboard', 'POST teams', 'GET servers',
    'GET voice/setup', 'GET talk', 'GET talk/connect', 'POST talk/connect', 'POST talk/latency-test/token', 'GET settings', 'GET settings/appearance', 'GET settings/connections',
    'GET settings/profile', 'PATCH settings/profile', 'DELETE settings/profile',
    'PUT settings/password', 'GET settings/security',
    'GET settings/connections/{provider}/redirect', 'GET settings/connections/{provider}/callback', 'GET settings/connections/link/{token}',
    'DELETE settings/connections/{provider}',
    'GET auth/discord/redirect', 'GET login', 'POST login', 'POST logout',
    'GET passkeys/confirm/options', 'POST passkeys/confirm', 'GET passkeys/login/options', 'POST passkeys/login',
    'GET two-factor-challenge', 'POST two-factor-challenge',
    'GET user/confirm-password', 'POST user/confirm-password', 'GET user/confirmed-password-status',
    'POST user/confirmed-two-factor-authentication', 'GET user/passkeys/options', 'POST user/passkeys',
    'POST user/two-factor-authentication', 'DELETE user/two-factor-authentication',
    'GET user/two-factor-qr-code', 'GET user/two-factor-recovery-codes', 'POST user/two-factor-recovery-codes',
    'GET user/two-factor-secret-key',
];

/**
 * Authenticated routes with a bound model id that are intentionally readable
 * (or actionable) by any logged-in user; the model enforces the rest.
 */
const OPEN_OBJECT_ROUTES = [
    'GET tournaments/{tournament}' => 'public tournament page; non-public events 404 (see event-visibility test)',
    'POST tournaments/{tournament}/checkin' => 'acts on the caller\'s own entry only',
    'POST tournaments/{tournament}/enroll' => 'enrolls the caller',
    'POST tournaments/{tournament}/withdraw' => 'withdraws the caller\'s own entry',
    'GET polls/{poll}' => 'poll page, event visibility',
    'POST polls/{poll}/vote' => 'one vote per caller',
    'POST schedule/{item}/favorite' => 'favourites are per caller',
    'DELETE schedule/{item}/favorite' => 'favourites are per caller',
    'POST teams/{team}/join' => 'creates a join request for the caller',
    'DELETE teams/{team}/leave' => 'removes the caller only',
    'DELETE friends/{user}' => 'removes the caller\'s own friendship with {user}',
    'POST friends/{user}/block' => 'caller blocks {user}',
    'DELETE friends/{user}/block' => 'caller unblocks {user}',
    'POST jukebox/{jukeboxItem}/vote' => 'one vote per caller, checked-in gate',
    'POST jukebox/{jukeboxItem}/skip-vote' => 'one vote per caller, checked-in gate',
    'POST food-orders/{foodOrder}/items' => 'orders for the caller on an open order',
    'GET voice/installers/{installer}/download' => 'published installers are for all users',
    'DELETE user/passkeys/{passkey}' => 'scoped to the caller\'s own passkeys (covered by passkey scenario)',
    'POST screen/{event}/control/{scene}' => 'role middleware',
    'DELETE talk/devices/{device}' => 'VoiceClientTokenPolicy revoke inside RevokeVoiceClientToken: owner only (tests/Feature/WebVoice/NativeClient)',
    'POST talk/channels/{channel}/token' => 'WebVoiceChannelPolicy join inside IssueJoinToken: roster or general only, tests/Feature/WebVoice/WebVoiceHttpTest',
    'GET talk/channels/{channel}/messages' => 'WebVoiceChannelPolicy view inside ChannelMessages: roster or general only',
    'POST talk/channels/{channel}/messages' => 'WebVoiceChannelPolicy post inside PostMessage, author is the caller',
    'DELETE talk/messages/{message}' => 'moderate ability inside DeleteMessage: admin only',
    'POST talk/channels/{channel}/participants/{user}/mute' => 'moderate ability inside MuteParticipant: admin only',
    'DELETE talk/channels/{channel}/participants/{user}/mute' => 'moderate ability inside MuteParticipant: admin only',
    'POST talk/channels/{channel}/participants/{user}/remove' => 'moderate ability inside RemoveParticipant: admin only',
];

/** @return list<LaravelRoute> */
function allRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (LaravelRoute $r) => ! str_starts_with($r->uri(), '_ignition') && $r->uri() !== 'sanctum/csrf-cookie',
    ));
}

function routeKey(LaravelRoute $route): string
{
    $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS', 'QUERY']));
    $method = count($methods) > 1 ? $methods[0] : ($methods[0] ?? 'GET');

    return $method.' '.$route->uri();
}

/** @return list<string> */
function middlewareOf(LaravelRoute $route): array
{
    return array_values(array_filter($route->gatherMiddleware(), 'is_string'));
}

function isAuthenticated(LaravelRoute $route): bool
{
    foreach (middlewareOf($route) as $mw) {
        if ($mw === 'auth' || str_starts_with($mw, 'auth:') || str_ends_with($mw, 'Middleware\\Authenticate')) {
            return true;
        }
    }

    return false;
}

function isGuestOnly(LaravelRoute $route): bool
{
    foreach (middlewareOf($route) as $mw) {
        if (str_contains($mw, 'RedirectIfAuthenticated') || str_starts_with($mw, 'guest')) {
            return true;
        }
    }

    return false;
}

function requiredRole(LaravelRoute $route): ?Role
{
    foreach (middlewareOf($route) as $mw) {
        foreach (['role:', 'App\\Http\\Middleware\\EnsureRole:'] as $prefix) {
            if (str_starts_with($mw, $prefix)) {
                return Role::from(substr($mw, strlen($prefix)));
            }
        }
    }

    return null;
}

function isFilamentPanelRoute(LaravelRoute $route): bool
{
    return str_starts_with($route->uri(), 'admin');
}

function isPublicAllowed(LaravelRoute $route): bool
{
    $key = routeKey($route);
    if (array_key_exists($key, PUBLIC_ROUTES)) {
        return true;
    }
    if (preg_match('#^livewire-[a-z0-9]+/#', $route->uri()) === 1) {
        return str_ends_with($route->uri(), '/update') ? array_key_exists('POST livewire-*/update', PUBLIC_ROUTES)
            : (str_ends_with($route->uri(), '/upload-file') ? array_key_exists('POST livewire-*/upload-file', PUBLIC_ROUTES)
                : array_key_exists('GET livewire-*', PUBLIC_ROUTES));
    }

    return false;
}

/** @return list<string> */
function modelParameters(LaravelRoute $route): array
{
    $names = $route->parameterNames();

    return array_values(array_diff($names, ['event', 'provider', 'token', 'scene', 'role', 'path', 'component', 'filename', 'export', 'import']));
}

/** @param array<string, string> $overrides */
function fillUri(LaravelRoute $route, array $overrides = []): string
{
    $uri = '/'.ltrim($route->uri(), '/');

    return (string) preg_replace_callback('/\{([a-zA-Z]+)(?::[a-z]+)?\??\}/', function (array $m) use ($overrides, $route) {
        if (isset($overrides[$m[1]])) {
            return $overrides[$m[1]];
        }
        $where = $route->wheres[$m[1]] ?? null;

        return match (true) {
            $m[1] === 'provider' => 'steam',
            $m[1] === 'role' => 'participant',
            $m[1] === 'notification' => '00000000-0000-4000-8000-000000000000',
            $where !== null && preg_match('/^\^?\[?0-9|\\\\d/', $where) === 1 => '1',
            default => '1',
        };
    }, $uri);
}

function send(object $test, LaravelRoute $route, string $uri)
{
    $method = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS', 'QUERY']));
    $m = strtolower($method[0] ?? 'get');

    return $test->call(strtoupper($m), $uri);
}

function actingAsRole(Role $role): User
{
    $user = User::factory()->create(['role' => $role]);
    test()->actingAs($user);

    return $user;
}

/* ---------------------------------------------------------------- 1. */

it('has no unauthenticated route outside the public allowlist', function () {
    $offenders = [];
    foreach (allRoutes() as $route) {
        if (isAuthenticated($route) || isGuestOnly($route) || isPublicAllowed($route)) {
            continue;
        }
        $offenders[] = routeKey($route);
    }

    expect($offenders)->toBe([], 'Unauthenticated routes missing from PUBLIC_ROUTES: '.implode(', ', $offenders));
});

it('keeps the public allowlist free of stale entries', function () {
    $registered = array_map(fn (LaravelRoute $r) => routeKey($r), allRoutes());
    $stale = [];
    foreach (array_keys(PUBLIC_ROUTES) as $key) {
        if (str_contains($key, 'livewire-*')) {
            continue;
        }
        if (! in_array($key, $registered, true) && $key !== 'POST _boost/browser-logs') {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'Stale PUBLIC_ROUTES entries: '.implode(', ', $stale));
});

it('declares an explicit expectation for every authenticated route', function () {
    $registered = array_map(fn (LaravelRoute $r) => routeKey($r), allRoutes());
    $missing = [];
    foreach (allRoutes() as $route) {
        if ((! isAuthenticated($route) && ! isGuestOnly($route)) || isFilamentPanelRoute($route) || requiredRole($route) !== null) {
            continue;
        }
        $key = routeKey($route);
        $hasObject = modelParameters($route) !== [];
        // Event-scoped routes are covered by the draft-event visibility test.
        if (! $hasObject && in_array('event', $route->parameterNames(), true)) {
            continue;
        }
        if (! $hasObject && ! in_array($key, SELF_SERVICE_ROUTES, true)) {
            $missing[] = $key.' (add to SELF_SERVICE_ROUTES only if it acts solely on the caller\'s own data)';
        }
        if ($hasObject && ! array_key_exists($key, OPEN_OBJECT_ROUTES) && ! in_array($key, idorCoveredRoutes(), true)) {
            $missing[] = $key.' (add an IDOR scenario or an OPEN_OBJECT_ROUTES entry)';
        }
    }

    $staleSelf = array_diff(SELF_SERVICE_ROUTES, $registered);
    $staleOpen = array_diff(array_keys(OPEN_OBJECT_ROUTES), $registered);

    expect($missing)->toBe([], implode("\n", $missing))
        ->and(array_values($staleSelf))->toBe([], 'Stale SELF_SERVICE_ROUTES: '.implode(', ', $staleSelf))
        ->and(array_values($staleOpen))->toBe([], 'Stale OPEN_OBJECT_ROUTES: '.implode(', ', $staleOpen));
});

/* ---------------------------------------------------------------- 2. */

it('bounces guests from every authenticated route', function () {
    $failures = [];
    foreach (allRoutes() as $route) {
        if (! isAuthenticated($route) || isGuestOnly($route)) {
            continue;
        }
        $response = send($this, $route, fillUri($route));
        $location = (string) $response->headers->get('Location');
        $bounced = $response->getStatusCode() === 401
            || ($response->isRedirect() && str_contains($location, 'login'));
        if (! $bounced) {
            $failures[] = routeKey($route).' -> '.$response->getStatusCode().' '.$location;
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

it('redirects authenticated users away from guest-only routes', function () {
    actingAsRole(Role::Participant);
    $failures = [];
    foreach (allRoutes() as $route) {
        if (! isGuestOnly($route) || ! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $response = $this->get(fillUri($route));
        if (! $response->isRedirect()) {
            $failures[] = routeKey($route).' -> '.$response->getStatusCode();
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

/* ---------------------------------------------------------------- 3. */

it('enforces EnsureRole middleware per role', function (Role $actor) {
    $event = Event::factory()->live()->create();
    $rank = [Role::Participant->value => 0, Role::Helper->value => 1, Role::Orga->value => 2, Role::Admin->value => 3];
    $failures = [];
    $checked = 0;

    foreach (allRoutes() as $route) {
        $required = requiredRole($route);
        if ($required === null) {
            continue;
        }
        $checked++;
        actingAsRole($actor);
        $status = send($this, $route, fillUri($route, ['event' => $event->slug]))->getStatusCode();
        $allowed = $rank[$actor->value] >= $rank[$required->value];
        if ($allowed && $status === 403) {
            $failures[] = routeKey($route)." wrongly denied for {$actor->value}";
        }
        $hasBoundModel = modelParameters($route) !== [] || in_array('scene', $route->parameterNames(), true);
        // Implicit model binding runs before the role middleware, so a bogus id yields 404 (never 200).
        if (! $allowed && $status !== 403 && ! ($hasBoundModel && $status === 404)) {
            $failures[] = routeKey($route)." not denied for {$actor->value} (got {$status})";
        }
    }

    expect($checked)->toBeGreaterThan(5)->and($failures)->toBe([], implode("\n", $failures));
})->with([Role::Participant, Role::Helper, Role::Orga, Role::Admin]);

it('keeps the Filament admin panel closed to guests and participants', function () {
    $failures = [];
    $paths = [];
    foreach (allRoutes() as $route) {
        if (! isFilamentPanelRoute($route) || ! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
            continue;
        }
        if (Str::contains($route->uri(), ['login', 'password', 'register', 'email-verification'])) {
            continue;
        }
        $paths[] = '/'.$route->uri();
    }

    foreach ($paths as $path) {
        $guest = $this->get($path);
        if (! $guest->isRedirect() || ! str_contains((string) $guest->headers->get('Location'), 'login')) {
            $failures[] = "guest {$path} -> ".$guest->getStatusCode();
        }
    }
    actingAsRole(Role::Participant);
    foreach ($paths as $path) {
        $status = $this->get($path)->getStatusCode();
        if ($status !== 403) {
            $failures[] = "participant {$path} -> {$status}";
        }
    }

    expect(count($paths))->toBeGreaterThan(10)->and($failures)->toBe([], implode("\n", $failures));
});

/* ---------------------------------------------------------------- 4. */

it('hides non-public events from participants on every event-scoped route', function () {
    $draft = Event::factory()->draft()->create();
    actingAsRole(Role::Participant);
    $failures = [];
    $checked = 0;

    foreach (allRoutes() as $route) {
        if (! isAuthenticated($route) || requiredRole($route) !== null || ! in_array('event', $route->parameterNames(), true)) {
            continue;
        }
        // Public beamer page has its own gate; jukebox skip is a moderator
        // action refused inside SkipCurrent (jukebox.moderate) with a flash.
        if (in_array(routeKey($route), ['GET screen/{event}', 'POST events/{event}/jukebox/skip'], true)) {
            continue;
        }
        $checked++;
        $response = send($this, $route, fillUri($route, ['event' => $draft->slug]));
        $status = $response->getStatusCode();
        // A FormRequest rejecting the (empty) payload runs before the
        // controller and is also harmless; real payloads for the key write
        // routes are covered by the dedicated test below.
        $validationOnly = $status === 302 && session()->has('errors');
        if (! in_array($status, [403, 404], true) && ! $validationOnly) {
            $failures[] = routeKey($route)." -> {$status}";
        }
    }

    expect($checked)->toBeGreaterThan(20)->and($failures)->toBe([], implode("\n", $failures));
});

it('refuses valid writes against a non-public event', function () {
    $draft = Event::factory()->draft()->create();
    $user = actingAsRole(Role::Participant);

    $this->post("/events/{$draft->slug}/register", ['ticket_type' => 'standard'])->assertStatus(404);
    $this->post("/events/{$draft->slug}/lfg", ['game' => 'CS2', 'title' => 'Need 2', 'slots_needed' => 2, 'duration_hours' => 2])
        ->assertRedirect(); // the action refuses with a flash; nothing is persisted
    expect(EventRegistration::where('user_id', $user->id)->count())->toBe(0)
        ->and(LfgPost::count())->toBe(0);
});

it('puts every state-changing route behind CSRF verification', function () {
    // Token/signature-authenticated machine endpoints and stateless (cookie-less) endpoints.
    $exempt = [
        'api/discord/interactions', 'internal/discord/gateway', 'internal/files/tusd-hook', 'api/telemetry/cs2/{serverLink}',
        'api/voice-client/device', 'api/voice-client/token', 'api/voice-client/v1/rooms/{room}/token', 'api/voice-client/v1/rooms/{room}/messages', 'api/voice-client/v1/messages/{message}', 'api/voice-client/v1/broadcasting/auth', 'api/voice-client/v1/notifications/read-all', 'api/voice-client/v1/notifications/{notification}/read', 'api/voice-client/v1/notification-preferences',
        'api/voice-client/v1/polls/{poll}/vote', 'api/voice-client/v1/food-orders/{foodOrder}/items', 'api/voice-client/v1/food-order-items/{foodOrderItem}',
        'api/voice-client/v1/events/{event}/files/uploads/ticket', 'api/voice-client/v1/files/{sharedFile}',
        'api/voice-client/v1/tournaments/{tournament}/checkin', 'api/voice-client/v1/matches/{match}/report', 'api/voice-client/v1/matches/{match}/confirm',
        'api/voice-client/v1/matches/{match}/dispute', 'api/voice-client/v1/matches/{match}/report/withdraw',
        'api/voice-client/v1/profile', 'api/voice-client/v1/connections/{provider}/link-url', 'api/voice-client/v1/connections/{provider}',
        'api/voice-client/v1/friends/requests', 'api/voice-client/v1/friends/requests/{friendship}/accept', 'api/voice-client/v1/friends/requests/{friendship}/decline',
        'api/voice-client/v1/friends/requests/{friendship}', 'api/voice-client/v1/friends/{user}', 'api/voice-client/v1/users/{user}/block',
        '_boost/browser-logs', 'csp-report', 'storage/{path}',
    ];
    $failures = [];

    foreach (allRoutes() as $route) {
        if (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === [] || in_array($route->uri(), $exempt, true)) {
            continue;
        }
        $mw = middlewareOf($route);
        $viaWeb = in_array('web', $mw, true);
        $viaPanel = isFilamentPanelRoute($route) && in_array('Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery', $mw, true);
        $livewire = preg_match('#^livewire-[a-z0-9]+/#', $route->uri()) === 1;
        if (! $viaWeb && ! $viaPanel && ! $livewire) {
            $failures[] = routeKey($route);
        }
    }

    expect($failures)->toBe([], 'State-changing routes without CSRF protection: '.implode(', ', $failures));
});

/* ---------------------------------------------------------------- 5. IDOR */

/**
 * Scenario: object owned by user A (and a bystander C where needed); user B,
 * who is neither owner nor staff, must be denied with 403/404.
 *
 * @return array<string, array{covers: list<string>, build: Closure(User, User): list<array<int, mixed>>}>
 */
function idorScenarios(): array
{
    return [
        'friend request cancel/respond by a bystander' => [
            'covers' => ['DELETE friends/{friendship}/cancel', 'PATCH friends/{friendship}/respond'],
            'build' => function (User $a, User $b) {
                $f = Friendship::factory()->create(['requester_id' => $a->id, 'addressee_id' => User::factory()->create()->id]);

                return [['DELETE', "/friends/{$f->id}/cancel"], ['PATCH', "/friends/{$f->id}/respond", ['accept' => true]]];
            },
        ],
        'foreign notification' => [
            'covers' => ['POST notifications/{notification}/read'],
            'build' => function (User $a, User $b) {
                $n = DatabaseNotification::create([
                    'id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => $a->getMorphClass(),
                    'notifiable_id' => $a->id, 'data' => ['k' => 'v'],
                ]);

                return [['POST', "/notifications/{$n->id}/read"]];
            },
        ],
        'pending shared file of another user' => [
            'covers' => ['GET files/{sharedFile}/download', 'DELETE files/{sharedFile}'],
            'build' => function (User $a, User $b) {
                $file = SharedFile::factory()->create(['user_id' => $a->id]);

                return [['GET', "/files/{$file->id}/download"], ['DELETE', "/files/{$file->id}"]];
            },
        ],
        'approved shared file deletion by a non-owner' => [
            'covers' => [],
            'build' => function (User $a, User $b) {
                $file = SharedFile::factory()->approved()->create(['user_id' => $a->id]);

                return [['DELETE', "/files/{$file->id}"]];
            },
        ],
        'pending gallery photo of another user' => [
            'covers' => ['GET gallery/photos/{eventPhoto}', 'GET gallery/photos/{eventPhoto}/thumb', 'DELETE gallery/photos/{eventPhoto}'],
            'build' => function (User $a, User $b) {
                $p = EventPhoto::factory()->create(['uploaded_by' => $a->id]);

                return [['GET', "/gallery/photos/{$p->id}"], ['GET', "/gallery/photos/{$p->id}/thumb"], ['DELETE', "/gallery/photos/{$p->id}"]];
            },
        ],
        'approved gallery photo deletion by a non-owner' => [
            'covers' => [],
            'build' => function (User $a, User $b) {
                $p = EventPhoto::factory()->approved()->create(['uploaded_by' => $a->id]);

                return [['DELETE', "/gallery/photos/{$p->id}"]];
            },
        ],
        'jukebox item removal by a non-owner' => [
            'covers' => ['DELETE jukebox/{jukeboxItem}'],
            'build' => function (User $a, User $b) {
                $i = JukeboxItem::factory()->create(['added_by' => $a->id]);

                return [['DELETE', "/jukebox/{$i->id}"]];
            },
        ],
        'LFG post removal by a non-owner' => [
            'covers' => ['DELETE lfg/{lfgPost}'],
            'build' => function (User $a, User $b) {
                $p = LfgPost::factory()->create(['user_id' => $a->id]);

                return [['DELETE', "/lfg/{$p->id}"]];
            },
        ],
        'food order item removal by a non-owner' => [
            'covers' => ['DELETE food-order-items/{foodOrderItem}'],
            'build' => function (User $a, User $b) {
                $i = FoodOrderItem::factory()->create(['user_id' => $a->id, 'food_order_id' => FoodOrder::factory()->open()->create()->id]);

                return [['DELETE', "/food-order-items/{$i->id}"]];
            },
        ],
        'team management by a non-owner' => [
            'covers' => ['PATCH teams/{team}', 'GET teams/{team}/edit', 'POST teams/{team}/requests/{teamRequest}', 'POST teams/{team}/transfer'],
            'build' => function (User $a, User $b) {
                $team = Team::factory()->create(['owner_id' => $a->id]);
                $req = TeamJoinRequest::factory()->create(['team_id' => $team->id]);

                return [
                    ['PATCH', "/teams/{$team->id}"],
                    ['GET', "/teams/{$team->id}/edit"],
                    ['POST', "/teams/{$team->id}/requests/{$req->id}"],
                    ['POST', "/teams/{$team->id}/transfer"],
                ];
            },
        ],
    ];
}

/** @return list<string> */
function idorCoveredRoutes(): array
{
    $covered = [];
    foreach (idorScenarios() as $scenario) {
        array_push($covered, ...$scenario['covers']);
    }
    // Routes whose IDOR coverage lives in dedicated, richer suites.
    array_push(
        $covered,
        'POST matches/{match}/confirm', 'POST matches/{match}/dispute', 'POST matches/{match}/go',
        'POST matches/{match}/report', 'POST matches/{match}/report/correct', 'POST matches/{match}/report/withdraw',
        'POST events/{event}/seating/{seat}',
    );

    return $covered;
}

it('denies foreign object ids on id-bearing routes (IDOR)', function (string $name) {
    $scenario = idorScenarios()[$name];
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $requests = ($scenario['build'])($owner, $intruder);
    $snapshot = DB::select("select tablename from pg_tables where schemaname = 'public' order by 1");
    $counts = [];
    foreach ($snapshot as $row) {
        $counts[$row->tablename] = DB::table($row->tablename)->count();
    }
    $this->actingAs($intruder);

    foreach ($requests as $request) {
        [$method, $uri] = $request;
        $status = $this->call($method, $uri, $request[2] ?? [])->getStatusCode();
        // 403/404 is the policy outcome. Actions that translate a domain
        // refusal into a flash-and-redirect (302) or a validation 422 are
        // acceptable only because the row counts below prove nothing changed.
        expect($status)->toBeIn([302, 403, 404, 422], "{$method} {$uri} as a bystander returned {$status}");
    }

    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count, "{$table} row count changed after bystander requests");
    }
})->with(fn () => array_keys(idorScenarios()));

it('rejects match actions from users who are not entrants', function () {
    fakeDiscord();
    fakeMumble();
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);
    app(StartTournament::class)->handle($tournament);
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->first();
    $this->actingAs(User::factory()->create());

    foreach (['confirm', 'dispute', 'go', 'report', 'report/correct', 'report/withdraw'] as $action) {
        $status = $this->post("/matches/{$match->id}/{$action}", ['score1' => 1, 'score2' => 0, 'lock_version' => 0])->getStatusCode();
        expect($status)->toBeIn([403, 404, 422], "matches/{id}/{$action} returned {$status}");
        expect($status)->not->toBeIn([200, 302], "matches/{id}/{$action} must not succeed for a stranger");
    }
});
