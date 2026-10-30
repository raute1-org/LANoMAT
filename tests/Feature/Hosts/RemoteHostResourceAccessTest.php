<?php

use App\Models\User;
use App\Modules\Hosts\Filament\Resources\RemoteHosts\Pages\CreateRemoteHost;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

it('rejects a malformed hostname in the create form with a German message', function () {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateRemoteHost::class)
        ->fillForm(['name' => 'X', 'hostname' => 'not a host;rm', 'ssh_port' => 22, 'ssh_user' => 'u', 'ssh_private_key' => 'k'])
        ->call('create')
        ->assertHasFormErrors(['hostname']);
});
