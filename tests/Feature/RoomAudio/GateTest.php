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
use App\Modules\RoomAudio\Models\RoomAudioSetting;
use App\Modules\RoomAudio\Support\ReceiverState;
use App\Modules\RoomAudio\Testing\FakeReceiverClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->receiver = new FakeReceiverClient;
    $this->receiver->willReturnState(new ReceiverState(
        connected: true,
        power: true,
        volume: 30.0,
        volumeMax: 98.0,
        mute: false,
        source: 'DVD',
        mode: 'stereo',
        sources: [['code' => 'DVD', 'name' => 'Blu-ray']],
        modes: [['key' => 'stereo', 'name' => 'Stereo']],
    ));
    app()->instance(ReceiverClient::class, $this->receiver);
});

/**
 * @return array<string, array{0: string, 1: Closure(User): mixed}>
 */
function roomAudioActions(): array
{
    return [
        'power' => ['power', fn (User $u) => app(SetReceiverPower::class)($u, true)],
        'volume' => ['volume', fn (User $u) => app(SetReceiverVolume::class)($u, 30.0)],
        'mute' => ['mute', fn (User $u) => app(SetReceiverMute::class)($u, true)],
        'source' => ['source', fn (User $u) => app(SetReceiverSource::class)($u, 'DVD')],
        'mode' => ['mode', fn (User $u) => app(SetReceiverMode::class)($u, 'stereo')],
        'ceiling' => ['ceiling', fn (User $u) => app(SetVolumeCeiling::class)($u, 50)],
    ];
}

it('lets orga and admin through the room-audio.control gate', function (Role $role) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('room-audio.control'))->toBeTrue();
})->with([
    'orga' => [Role::Orga],
    'admin' => [Role::Admin],
]);

it('refuses helper and participant at the gate', function (Role $role) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->denies('room-audio.control'))->toBeTrue();
})->with([
    'helper' => [Role::Helper],
    'participant' => [Role::Participant],
]);

it('refuses every action for non-orga users and never reaches the receiver', function (string $name, Closure $invoke) {
    $user = User::factory()->create(['role' => Role::Helper]);

    expect(fn () => $invoke($user))->toThrow(AuthorizationException::class);
    expect($this->receiver->calls)->toBe([]);
    expect(RoomAudioSetting::current()->volume_ceiling)->toBe(60);
})->with(roomAudioActions());

it('refuses a participant on every action too', function (string $name, Closure $invoke) {
    $user = User::factory()->create(['role' => Role::Participant]);

    expect(fn () => $invoke($user))->toThrow(AuthorizationException::class);
    expect($this->receiver->calls)->toBe([]);
})->with(roomAudioActions());
