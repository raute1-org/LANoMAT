<?php

declare(strict_types=1);

use App\Modules\Voice\Actions\SetCurrentInstaller;
use App\Modules\Voice\Domain\VoiceClientPlatform;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Models\VoiceClientInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('stores installers on the private local disk, never on a public disk or as a public URL', function () {
    Storage::disk('local')->put('voice-installers/mumble-windows-1.0.exe', 'binary-content');

    $installer = VoiceClientInstaller::factory()->create([
        'provider' => VoiceProvider::Mumble->value,
        'platform' => VoiceClientPlatform::Windows->value,
        'path' => 'voice-installers/mumble-windows-1.0.exe',
    ]);

    Storage::disk('local')->assertExists($installer->path);

    // The `local` disk config carries no `url` key (see config/filesystems.php)
    // — there is no public path to construct in the first place.
    expect(config('filesystems.disks.local'))->not->toHaveKey('url');
});

it('unsets the previous current installer for the same (provider, platform) when a new one is marked current', function () {
    $previous = VoiceClientInstaller::factory()->create([
        'provider' => VoiceProvider::Mumble->value,
        'platform' => VoiceClientPlatform::Windows->value,
        'is_current' => true,
    ]);

    $next = VoiceClientInstaller::factory()->create([
        'provider' => VoiceProvider::Mumble->value,
        'platform' => VoiceClientPlatform::Windows->value,
        'is_current' => false,
    ]);

    app(SetCurrentInstaller::class)->handle($next);

    expect($previous->refresh()->is_current)->toBeFalse()
        ->and($next->refresh()->is_current)->toBeTrue();
});

it('does not affect the current installer of a different (provider, platform) pair', function () {
    $otherPlatform = VoiceClientInstaller::factory()->create([
        'provider' => VoiceProvider::Mumble->value,
        'platform' => VoiceClientPlatform::MacOS->value,
        'is_current' => true,
    ]);

    $target = VoiceClientInstaller::factory()->create([
        'provider' => VoiceProvider::Mumble->value,
        'platform' => VoiceClientPlatform::Windows->value,
        'is_current' => false,
    ]);

    app(SetCurrentInstaller::class)->handle($target);

    expect($otherPlatform->refresh()->is_current)->toBeTrue()
        ->and($target->refresh()->is_current)->toBeTrue();
});
