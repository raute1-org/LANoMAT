<?php

use App\Support\Modules\Module;
use App\Support\Modules\Modules;

it('is enabled under the full profile', function () {
    expect((new Modules('full'))->enabled(Module::RoomAudio))->toBeTrue();
});

it('is disabled under the core profile', function () {
    expect((new Modules('core'))->enabled(Module::RoomAudio))->toBeFalse();
});

it('can be switched off individually under the full profile', function () {
    expect((new Modules('full', ['room_audio' => false]))->enabled(Module::RoomAudio))->toBeFalse();
});

it('has no module requirements', function () {
    expect(Module::RoomAudio->requires())->toBe([])
        ->and(Module::RoomAudio->isCore())->toBeFalse();
});

it('exposes the sidecar config keys', function () {
    expect(config('services.room_audio'))->toHaveKeys(['rest_url', 'token']);
});
