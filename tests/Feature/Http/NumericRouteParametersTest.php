<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Every id-bound route parameter of the app's own routes (everything except
 * slug / token / string params) must 404 on a non-numeric value instead of
 * hitting the database and 500ing (Postgres rejects "abc" for a bigint).
 *
 * @return list<array{string, string}> [method, uri-template]
 */
function idBoundRoutes(): array
{
    $idParams = [
        'user', 'team', 'teamRequest', 'tournament', 'match', 'notification', 'seat', 'eventPhoto',
        'foodOrder', 'foodOrderItem', 'friendship', 'installer', 'item', 'jukeboxItem', 'lfgPost',
        'poll', 'scene', 'serverLink', 'sharedFile', 'tombolaPrize',
    ];

    $found = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        $action = $route->getActionName();
        if (! str_starts_with($action, 'App\\')) {
            continue;
        }
        if (array_intersect($idParams, $route->parameterNames()) === []) {
            continue;
        }
        $method = collect($route->methods())->first(fn (string $m): bool => ! in_array($m, ['HEAD', 'OPTIONS'], true));
        $found[] = [$method, $route->uri()];
    }

    return $found;
}

function withNonNumeric(string $uri, string $value): string
{
    $idParams = ['user', 'team', 'teamRequest', 'tournament', 'match', 'notification', 'seat', 'eventPhoto',
        'foodOrder', 'foodOrderItem', 'friendship', 'installer', 'item', 'jukeboxItem', 'lfgPost',
        'poll', 'scene', 'serverLink', 'sharedFile', 'tombolaPrize'];

    $uri = (string) preg_replace_callback('/\{(\w+)(?::\w+)?\??\}/', fn (array $m): string => in_array($m[1], $idParams, true) ? $value : 'x', $uri);

    return '/'.ltrim($uri, '/');
}

it('discovers a sensible number of id-bound routes', function () {
    expect(count(idBoundRoutes()))->toBeGreaterThan(40);
});

it('returns 404 for a non-numeric id on every id-bound route (guest)', function (string $value) {
    foreach (idBoundRoutes() as [$method, $uri]) {
        $status = $this->call($method, withNonNumeric($uri, $value))->getStatusCode();
        expect($status)->toBe(404, "$method $uri with '$value' gave $status");
    }
})->with(['abc', '1.5', '2147483648abc', '99999999999999999999']);

it('returns 404 for a non-numeric id on every id-bound route (authenticated)', function (string $value) {
    $this->actingAs(User::factory()->create(['role' => 'orga']));

    foreach (idBoundRoutes() as [$method, $uri]) {
        $status = $this->call($method, withNonNumeric($uri, $value))->getStatusCode();
        expect($status)->toBe(404, "$method $uri with '$value' gave $status");
    }
})->with(['abc', '1.5']);
