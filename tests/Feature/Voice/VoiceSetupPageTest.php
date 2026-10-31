<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Voice\Domain\VoiceClientPlatform;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Models\VoiceClientInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['services.voice.providers' => ['mumble']]);
});

it('redirects a guest to login', function () {
    $this->get('/voice/setup')->assertRedirect('/login');
});

it('renders the voice setup page for an authenticated participant with active providers and current installers', function () {
    $current = VoiceClientInstaller::factory()->current()->create([
        'provider' => VoiceProvider::Mumble->value,
        'platform' => VoiceClientPlatform::Windows->value,
        'version' => '1.5.0',
        'original_name' => 'mumble-setup.exe',
    ]);

    // A non-current installer must not surface as the download for its
    // platform.
    VoiceClientInstaller::factory()->create([
        'provider' => VoiceProvider::Mumble->value,
        'platform' => VoiceClientPlatform::Windows->value,
        'version' => '1.4.0',
        'is_current' => false,
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/voice/setup')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Voice/Setup')
            ->where('providers.0.provider', VoiceProvider::Mumble->value)
            ->where('providers.0.installers.0.version', '1.5.0')
            ->where('providers.0.installers.0.originalName', $current->original_name)
        );
});

it('shows an empty installer state when no current installer exists for a platform', function () {
    $this->actingAs(User::factory()->create())
        ->get('/voice/setup')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Voice/Setup')
            ->where('providers.0.installers', [])
        );
});

it('passes the Mumble server password to participants and nothing for providers without one', function () {
    config([
        'services.voice.providers' => ['mumble', 'teamspeak'],
        'services.mumble.server_password' => 'join-secret',
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/voice/setup')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('providers.0.provider', VoiceProvider::Mumble->value)
            ->where('providers.0.password', 'join-secret')
            ->where('providers.1.provider', VoiceProvider::TeamSpeak->value)
            ->where('providers.1.password', null)
        );
});

it('sends no password when the Mumble server has none', function () {
    config(['services.mumble.server_password' => '']);

    $this->actingAs(User::factory()->create())
        ->get('/voice/setup')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('providers.0.password', null)
        );
});

it('carries the name of the logged-in user in the connect link', function () {
    config(['services.mumble.host' => 'voice.lan', 'services.mumble.port' => 64738]);

    $this->actingAs(User::factory()->create(['name' => 'Max Mustermann']))
        ->get('/voice/setup')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('providers.0.joinLink', 'mumble://Max%20Mustermann@voice.lan:64738/Lobby')
        );
});
