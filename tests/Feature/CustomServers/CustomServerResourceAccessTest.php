<?php

use App\Models\User;
use App\Modules\CustomServers\Filament\Resources\CustomServers\Pages\CreateCustomServer;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Events\Models\Event;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('forbids participants from the custom-servers resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/custom-servers')
        ->assertForbidden();
});

it('forbids helpers from the custom-servers resource', function () {
    $this->actingAs(User::factory()->helper()->create())
        ->get('/admin/custom-servers')
        ->assertForbidden();
});

it('allows orga into the custom-servers resource and renders the list', function () {
    $host = RemoteHost::factory()->create();
    CustomServer::factory()->for($host, 'host')->create(['name' => 'Modded-MC-1']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/custom-servers')
        ->assertOk()
        ->assertSee('Modded-MC-1');
});

it('creates a custom server through the Filament form with an event selected', function () {
    $host = RemoteHost::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateCustomServer::class)
        ->fillForm([
            'name' => 'Modded-MC-2',
            'remote_host_id' => $host->id,
            'event_id' => $event->id,
            'image' => 'itzg/minecraft-server',
            'container_name' => 'mc-lan-2',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = CustomServer::query()->where('name', 'Modded-MC-2')->firstOrFail();

    expect($server->event->id)->toBe($event->id);
});
