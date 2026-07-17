<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Voice\Models\VoiceClientInstaller;
use App\Modules\Voice\Policies\VoiceClientInstallerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('enforces orga-only manage abilities on VoiceClientInstallerPolicy', function () {
    $policy = new VoiceClientInstallerPolicy;
    $installer = VoiceClientInstaller::factory()->create();

    $orga = User::factory()->orga()->create();
    $participant = User::factory()->create();

    expect($policy->viewAny($orga))->toBeTrue()
        ->and($policy->viewAny($participant))->toBeFalse()
        ->and($policy->create($orga))->toBeTrue()
        ->and($policy->create($participant))->toBeFalse()
        ->and($policy->update($orga, $installer))->toBeTrue()
        ->and($policy->update($participant, $installer))->toBeFalse()
        ->and($policy->delete($orga, $installer))->toBeTrue()
        ->and($policy->delete($participant, $installer))->toBeFalse();
});

it('allows any authenticated user to download, not just orga', function () {
    $policy = new VoiceClientInstallerPolicy;
    $installer = VoiceClientInstaller::factory()->create();

    $orga = User::factory()->orga()->create();
    $participant = User::factory()->create();

    expect($policy->download($orga, $installer))->toBeTrue()
        ->and($policy->download($participant, $installer))->toBeTrue();
});

it('forbids participants from the voice-client-installers Filament resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/voice-client-installers')
        ->assertForbidden();
});

it('allows orga into the voice-client-installers Filament resource', function () {
    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/voice-client-installers')
        ->assertOk();
});

it('redirects a guest to login for the installer download route', function () {
    $installer = VoiceClientInstaller::factory()->create();

    $this->get("/voice/installers/{$installer->id}/download")
        ->assertRedirect('/login');
});

it('streams the installer download for an authenticated user', function () {
    Storage::disk('local')->put('voice-installers/mumble-1.0.exe', 'binary-content');

    $installer = VoiceClientInstaller::factory()->create([
        'path' => 'voice-installers/mumble-1.0.exe',
        'original_name' => 'mumble-1.0.exe',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get("/voice/installers/{$installer->id}/download");

    $response->assertOk();
});
