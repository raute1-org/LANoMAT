<?php

use App\Models\User;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
