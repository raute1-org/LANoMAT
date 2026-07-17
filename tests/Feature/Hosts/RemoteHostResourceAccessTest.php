<?php

use App\Models\User;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids participants from the remote-hosts resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/remote-hosts')
        ->assertForbidden();
});

it('forbids helpers from the remote-hosts resource', function () {
    $this->actingAs(User::factory()->helper()->create())
        ->get('/admin/remote-hosts')
        ->assertForbidden();
});

it('allows orga into the remote-hosts resource and renders the list', function () {
    RemoteHost::factory()->create([
        'name' => 'Lancache-Node-1',
        'ssh_private_key' => 'PRIVATE-KEY-PEM-SECRET',
    ]);

    $response = $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/remote-hosts')
        ->assertOk()
        ->assertSee('Lancache-Node-1');

    $response->assertDontSee('PRIVATE-KEY-PEM-SECRET');
});
