<?php

use App\Models\User;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Filament\Resources\ServerLinks\Pages\ListServerLinks;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Testing\FakePelicanClient;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('forbids participants from the server links resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/server-links')
        ->assertForbidden();
});

it('forbids helpers from the server links resource', function () {
    $this->actingAs(User::factory()->helper()->create())
        ->get('/admin/server-links')
        ->assertForbidden();
});

it('allows orga into the server links resource and renders the status label', function () {
    ServerLink::factory()->create([
        'pelican_server_id' => 'abc-123',
        'status' => ServerLinkStatus::Ready,
    ]);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/server-links')
        ->assertOk()
        // i18n gate: German status label (see lang/de/gameservers.php).
        ->assertSee('Bereit');
});

it('calls PelicanClient::powerAction(Start) via the Start power action', function () {
    $fake = fakePelican();
    $match = GameMatch::factory()->create();
    $link = ServerLink::factory()->create([
        'match_id' => $match->id,
        'pelican_server_id' => '7',
        'pelican_server_identifier' => '81ec06ec',
        'status' => ServerLinkStatus::Ready,
    ]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListServerLinks::class)
        ->callTableAction('start', $link);

    // The Client API is addressed by the short identifier, never the numeric id.
    $fake->assertPowerAction('81ec06ec', PowerAction::Start);
});

it('resolves and stores a missing identifier from the Application API before powering', function () {
    $fake = fakePelican();
    $server = $fake->createServer(FakePelicanClient::makeSpec('5'));
    $link = ServerLink::factory()->create([
        'pelican_server_id' => $server->id,
        'pelican_server_identifier' => null,
        'status' => ServerLinkStatus::Ready,
    ]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListServerLinks::class)->callTableAction('stop', $link);

    $fake->assertPowerAction($server->identifier, PowerAction::Stop);
    expect($link->fresh()->pelican_server_identifier)->toBe($server->identifier);
});
