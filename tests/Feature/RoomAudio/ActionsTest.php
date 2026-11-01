<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\RoomAudio\Actions\SetReceiverMode;
use App\Modules\RoomAudio\Actions\SetReceiverMute;
use App\Modules\RoomAudio\Actions\SetReceiverPower;
use App\Modules\RoomAudio\Actions\SetReceiverSource;
use App\Modules\RoomAudio\Actions\SetReceiverVolume;
use App\Modules\RoomAudio\Actions\SetVolumeCeiling;
use App\Modules\RoomAudio\Contracts\ReceiverClient;
use App\Modules\RoomAudio\Exceptions\ReceiverUnavailable;
use App\Modules\RoomAudio\Models\RoomAudioSetting;
use App\Modules\RoomAudio\Support\ReceiverState;
use App\Modules\RoomAudio\Testing\FakeReceiverClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orga = User::factory()->create(['role' => Role::Orga]);
    $this->receiver = new FakeReceiverClient;
    $this->receiver->willReturnState(new ReceiverState(
        connected: true,
        power: true,
        volume: 30.0,
        volumeMax: 98.0,
        mute: false,
        source: 'DVD',
        mode: 'stereo',
        sources: [
            ['code' => 'DVD', 'name' => 'Blu-ray'],
            ['code' => 'HDMI1', 'name' => 'Game Console'],
        ],
        modes: [
            ['key' => 'stereo', 'name' => 'Stereo'],
            ['key' => 'direct', 'name' => 'Direct'],
        ],
    ));
    app()->instance(ReceiverClient::class, $this->receiver);
});

it('defaults the ceiling to 60 and creates the settings row on demand', function () {
    expect(RoomAudioSetting::query()->count())->toBe(0);

    expect(RoomAudioSetting::current()->volume_ceiling)->toBe(60)
        ->and(RoomAudioSetting::query()->count())->toBe(1);
});

it('clamps volume to the ceiling', function () {
    $sent = app(SetReceiverVolume::class)($this->orga, 150.0);

    expect($sent)->toBe(60.0)
        ->and($this->receiver->calls)->toBe([['setVolume', 60.0]]);
});

it('rounds volume to half steps', function () {
    $sent = app(SetReceiverVolume::class)($this->orga, 32.3);

    expect($sent)->toBe(32.5)
        ->and($this->receiver->calls)->toBe([['setVolume', 32.5]]);
});

it('clamps negative volume to zero', function () {
    expect(app(SetReceiverVolume::class)($this->orga, -5.0))->toBe(0.0)
        ->and($this->receiver->calls)->toBe([['setVolume', 0.0]]);
});

it('caps volume at 98 even when the ceiling is higher', function () {
    app(SetVolumeCeiling::class)($this->orga, 98);

    expect(app(SetReceiverVolume::class)($this->orga, 150.0))->toBe(98.0);
});

it('rejects NaN and infinite volume without sending anything', function (float $volume) {
    expect(fn () => app(SetReceiverVolume::class)($this->orga, $volume))
        ->toThrow(InvalidArgumentException::class);

    expect($this->receiver->calls)->toBe([]);
})->with([
    'nan' => [NAN],
    'inf' => [INF],
    'negative inf' => [-INF],
]);

it('sets the ceiling and rejects values outside 0..98', function () {
    app(SetVolumeCeiling::class)($this->orga, 0);
    expect(RoomAudioSetting::current()->volume_ceiling)->toBe(0);

    app(SetVolumeCeiling::class)($this->orga, 98);
    expect(RoomAudioSetting::current()->volume_ceiling)->toBe(98);

    foreach ([99, -1] as $invalid) {
        expect(fn () => app(SetVolumeCeiling::class)($this->orga, $invalid))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(RoomAudioSetting::current()->volume_ceiling)->toBe(98);
});

it('passes power and mute through as booleans', function () {
    app(SetReceiverPower::class)($this->orga, false);
    app(SetReceiverMute::class)($this->orga, true);

    expect($this->receiver->calls)->toBe([
        ['setPower', false],
        ['setMute', true],
    ]);
});

it('accepts listed source codes and NET', function (string $code) {
    app(SetReceiverSource::class)($this->orga, $code);

    expect($this->receiver->calls)->toBe([['setSource', $code]]);
})->with(['DVD', 'HDMI1', 'NET']);

it('rejects unknown source codes without sending anything', function () {
    expect(fn () => app(SetReceiverSource::class)($this->orga, 'BOGUS'))
        ->toThrow(InvalidArgumentException::class);

    expect($this->receiver->calls)->toBe([]);
});

it('accepts listed mode keys and rejects others', function () {
    app(SetReceiverMode::class)($this->orga, 'direct');
    expect($this->receiver->calls)->toBe([['setMode', 'direct']]);

    expect(fn () => app(SetReceiverMode::class)($this->orga, 'surround-bogus'))
        ->toThrow(InvalidArgumentException::class);
    expect($this->receiver->calls)->toBe([['setMode', 'direct']]);
});

it('throws ReceiverUnavailable for a source while the receiver is down, not an unknown-source error', function () {
    $this->receiver->willReturnState(ReceiverState::unreachable());

    expect(fn () => app(SetReceiverSource::class)($this->orga, 'DVD'))
        ->toThrow(ReceiverUnavailable::class);

    expect($this->receiver->calls)->toBe([]);
});

it('throws ReceiverUnavailable for a mode while the receiver is down, not an unknown-mode error', function () {
    $this->receiver->willReturnState(ReceiverState::unreachable());

    expect(fn () => app(SetReceiverMode::class)($this->orga, 'stereo'))
        ->toThrow(ReceiverUnavailable::class);

    expect($this->receiver->calls)->toBe([]);
});

it('propagates ReceiverUnavailable from power, mute and volume when the receiver cannot be reached', function (string $action) {
    $this->receiver->willBeUnavailable();

    $call = match ($action) {
        'power' => fn () => app(SetReceiverPower::class)($this->orga, true),
        'mute' => fn () => app(SetReceiverMute::class)($this->orga, true),
        'volume' => fn () => app(SetReceiverVolume::class)($this->orga, 30.0),
    };

    expect($call)->toThrow(ReceiverUnavailable::class);
    expect($this->receiver->calls)->toBe([]);
})->with(['power', 'mute', 'volume']);
