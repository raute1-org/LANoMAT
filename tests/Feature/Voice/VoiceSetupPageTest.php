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
