<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\MinecraftQuery;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Domain\PelicanNode;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Filament\Resources\PermanentServers\Pages\EditPermanentServer;
use App\Modules\GameServers\Filament\Resources\PermanentServers\Pages\ListPermanentServers;
use App\Modules\GameServers\Filament\Resources\ServerLinks\Pages\ListServerLinks;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Testing\FakeMinecraftQuery;
use Database\Seeders\GamesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([PollServerStatusJob::class]);
    config(['services.pelican.owner_user_id' => '3', 'services.pelican.max_ram_mb' => 4096]);
    $this->pelican = fakePelican();
    $this->pelican->eggs['4'] = new PelicanEgg('4', 'Paper', ['MINECRAFT_VERSION' => 'latest']);
    app()->instance(MinecraftQuery::class, new FakeMinecraftQuery(FakeMinecraftQuery::serverInfo(players: 2)));
    fakeRemote();
    $this->seed(GamesSeeder::class);
    $this->game = Game::query()->where('slug', 'minecraft')->firstOrFail();
    $this->admin = User::factory()->admin()->create();
});

it('creates a Pelican permanent server from the seeded Minecraft preset on a chosen node', function () {
    $this->pelican->nodes = [new PelicanNode('2', 'always-on', 'node2.example.test')];
    $this->actingAs($this->admin);

    Livewire::test(ListPermanentServers::class)
        ->callAction('create', [
            'source' => 'pelican',
            'game_id' => $this->game->id,
            'preset_key' => 'paper',
            'title' => 'Survival',
            'node_id' => '2',
            'overrides' => ['MINECRAFT_VERSION' => '1.21.4'],
        ])
        ->assertNotified(__('gameservers.permanent.actions.created'));

    $link = ServerLink::query()->sole();
    expect($link->permanent)->toBeTrue()
        ->and($link->pelican_node_id)->toBe('2')
        ->and($this->pelican->created[0]['spec']->nodeId)->toBe('2')
        ->and($this->pelican->created[0]['spec']->environment['MINECRAFT_VERSION'])->toBe('1.21.4');
});

it('registers an external server through the form', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListPermanentServers::class)
        ->callAction('create', [
            'source' => 'external',
            'game_id' => $this->game->id,
            'title' => 'Cloud-Survival',
            'address' => '203.0.113.20',
            'port' => 25565,
            'query_protocol' => 'minecraft',
        ])
        ->assertNotified(__('gameservers.permanent.actions.created'));

    $link = ServerLink::query()->sole();
    expect($link->source)->toBe(ServerSource::External)
        ->and($link->status)->toBe(ServerLinkStatus::Ready)
        ->and($link->query_info['players'])->toBe(2);
});

it('requires address and port for an external server', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListPermanentServers::class)
        ->callAction('create', ['source' => 'external', 'game_id' => $this->game->id, 'title' => 'X'])
        ->assertHasActionErrors(['address', 'port']);

    expect(ServerLink::query()->count())->toBe(0);
});

it('offers power actions for Pelican and Docker servers but not for an external one', function () {
    $pelican = ServerLink::factory()->permanent(ServerSource::Pelican)->create(['pelican_server_id' => '1']);
    $docker = ServerLink::factory()->permanent(ServerSource::Docker)->create();
    $external = ServerLink::factory()->permanent(ServerSource::External)->create();
    $this->actingAs($this->admin);

    $list = Livewire::test(ListPermanentServers::class);

    foreach (['start', 'stop', 'restart'] as $action) {
        $list->assertTableActionVisible($action, $pelican)
            ->assertTableActionVisible($action, $docker)
            ->assertTableActionHidden($action, $external);
    }

    $list->assertTableActionVisible('remove', $external);
});

it('removes an external server from the table after confirmation', function () {
    $external = ServerLink::factory()->permanent(ServerSource::External)->create();
    $this->actingAs($this->admin);

    Livewire::test(ListPermanentServers::class)
        ->callTableAction('remove', $external)
        ->assertNotified(__('gameservers.permanent.actions.deleted'));

    expect(ServerLink::query()->count())->toBe(0);
});

it('edits the name, description and address of a permanent server', function () {
    $link = ServerLink::factory()->permanent(ServerSource::External)->create();
    $this->actingAs($this->admin);

    Livewire::test(EditPermanentServer::class, ['record' => $link->getRouteKey()])
        ->fillForm(['title' => 'Neu', 'description' => 'Mods: keine', 'address' => '203.0.113.99', 'port' => 25570, 'query_protocol' => 'minecraft'])
        ->call('save')
        ->assertHasNoFormErrors();

    $link->refresh();
    expect($link->title)->toBe('Neu')
        ->and($link->description)->toBe('Mods: keine')
        ->and($link->join_info->address)->toBe('203.0.113.99')
        ->and($link->join_info->port)->toBe(25570)
        ->and($link->permanent)->toBeTrue();
});

it('keeps the admin area closed to orga and participants', function (string $role) {
    $this->actingAs(User::factory()->state(['role' => Role::from($role)])->create());

    $this->get('/admin/permanent-servers')->assertForbidden();
})->with(['participant', 'orga']);

it('keeps permanent servers out of the match and event server resource', function () {
    $permanent = ServerLink::factory()->permanent()->create();
    $regular = ServerLink::factory()->create();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListServerLinks::class)
        ->assertCanSeeTableRecords([$regular])
        ->assertCanNotSeeTableRecords([$permanent]);
});
