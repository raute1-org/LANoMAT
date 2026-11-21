<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\JoinLinkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('builds the steam and native options for UT2004 from address and port', function () {
    $options = JoinLinkTemplates::forSlug('unreal-tournament-2004', '203.0.113.7', 7777);

    expect(array_column($options, 'key'))->toBe(['steam', 'native'])
        ->and(array_column($options, 'url'))->toBe([
            'steam://run/13230//203.0.113.7:7777',
            'ut2004://203.0.113.7:7777',
        ])
        ->and($options[0]['label'])->toBe('Steam')
        ->and($options[1]['label'])->toBe('Nativ')
        ->and($options[0]['hint'])->toContain('Steam-Bibliothek');
});

it('recognises UT2004 under its alias slugs, case-insensitively', function (string $slug) {
    expect(JoinLinkTemplates::forSlug($slug, '203.0.113.7', 7777))->toHaveCount(2);
})->with(['ut2004', 'UT2K4', 'unreal-tournament-2k4']);

it('brackets IPv6 addresses and accepts host names', function () {
    expect(JoinLinkTemplates::forSlug('ut2004', '2001:db8::1', 7777)[1]['url'])->toBe('ut2004://[2001%3Adb8%3A%3A1]:7777')
        ->and(JoinLinkTemplates::forSlug('ut2004', 'ut.lan.example', 7777)[1]['url'])->toBe('ut2004://ut.lan.example:7777');
});

it('offers nothing for unknown games or invalid addresses and ports', function (?string $address, ?int $port) {
    expect(JoinLinkTemplates::forSlug('ut2004', $address, $port))->toBe([]);
})->with([
    'script injection' => ['1.2.3.4/../x"><b>', 7777],
    'space' => ['evil host', 7777],
    'credentials' => ['user@host', 7777],
    'newline' => ["host\nx", 7777],
    'scheme' => ['javascript:alert(1)', 7777],
    'no address' => [null, 7777],
    'no port' => ['203.0.113.7', null],
    'port zero' => ['203.0.113.7', 0],
    'port too high' => ['203.0.113.7', 70000],
]);

it('offers nothing for a game without templates', function () {
    expect(JoinLinkTemplates::forSlug('counter-strike-2', '203.0.113.7', 27015))->toBe([])
        ->and(JoinLinkTemplates::optionsFor(null, new JoinInfo('203.0.113.7', 27015)))->toBe([]);
});

it('allows only the whitelisted url schemes', function (string $url, bool $allowed) {
    expect(JoinLinkTemplates::isAllowedUrl($url))->toBe($allowed);
})->with([
    ['steam://run/13230//203.0.113.7:7777', true],
    ['steam://connect/203.0.113.7:27015', true],
    ['ut2004://203.0.113.7:7777', true],
    ['https://example.org/x', true],
    ['http://example.org/x', true],
    ['javascript:alert(1)', false],
    ['data://text/html,x', false],
    ['file:///etc/passwd', false],
    ['ut2004://a b', false],
    ['ut2004://a"b', false],
    ['//example.org', false],
]);

it('exposes join options on /servers only for the UT2004 permanent server', function () {
    $ut = Game::factory()->create(['name' => 'Unreal Tournament 2004', 'slug' => 'unreal-tournament-2004']);
    $other = Game::factory()->create(['name' => 'Minecraft', 'slug' => 'minecraft']);
    ServerLink::factory()->permanent()->create([
        'game_id' => $ut->id,
        'title' => 'UT2004 DM',
        'join_info' => new JoinInfo(address: '203.0.113.7', port: 7777, connectString: '203.0.113.7:7777'),
    ]);
    ServerLink::factory()->permanent()->create([
        'game_id' => $other->id,
        'title' => 'Survival',
        'join_info' => new JoinInfo(address: '203.0.113.8', port: 25565),
    ]);

    $this->actingAs(User::factory()->create())->get('/servers/permanent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Servers/Permanent')
            ->where('labels.join', 'Beitreten')
            ->where('servers.0.title', 'Survival')
            ->where('servers.0.joinOptions', [])
            ->where('servers.1.title', 'UT2004 DM')
            ->where('servers.1.connectString', '203.0.113.7:7777')
            ->where('servers.1.joinOptions.0.url', 'steam://run/13230//203.0.113.7:7777')
            ->where('servers.1.joinOptions.1.url', 'ut2004://203.0.113.7:7777')
        );
});

it('does not expose join options to guests', function () {
    $this->get('/servers/permanent')->assertRedirect(route('login'));
});

it('exposes join options on the event server list only for visible (listed) servers', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'Unreal Tournament 2004', 'slug' => 'unreal-tournament-2004']);
    $base = ['event_id' => $event->id, 'game_id' => $game->id, 'join_info' => new JoinInfo(address: '203.0.113.7', port: 7777)];

    ServerLink::factory()->create($base + ['title' => 'Sichtbar', 'status' => ServerLinkStatus::Ready]);
    ServerLink::factory()->create($base + ['title' => 'Versteckt', 'status' => ServerLinkStatus::Failed]);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/servers")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 1)
            ->where('servers.0.title', 'Sichtbar')
            ->where('servers.0.joinOptions.0.url', 'steam://run/13230//203.0.113.7:7777')
        );
});
