<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\RoomAudio\Contracts\ReceiverClient;
use App\Modules\RoomAudio\Filament\Pages\RoomControl;
use App\Modules\RoomAudio\Models\RoomAudioSetting;
use App\Modules\RoomAudio\Support\ReceiverState;
use App\Modules\RoomAudio\Testing\FakeReceiverClient;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fakeReceiver(bool $power = true, bool $connected = true): FakeReceiverClient
{
    $fake = new FakeReceiverClient;
    $fake->willReturnState(new ReceiverState(
        connected: $connected,
        power: $power,
        volume: 30.5,
        volumeMax: 98.0,
        mute: false,
        source: 'DVD',
        mode: 'stereo',
        modeName: 'Stereo',
        sources: [['code' => 'DVD', 'name' => 'Blu-ray'], ['code' => 'HDMI1', 'name' => '<b>Console</b>']],
        modes: [['key' => 'stereo', 'name' => 'Stereo']],
    ));
    app()->instance(ReceiverClient::class, $fake);

    return $fake;
}

it('forbids participants and helpers', function () {
    fakeReceiver();

    $this->actingAs(User::factory()->create())->get('/admin/room-control')->assertForbidden();
    $this->actingAs(User::factory()->helper()->create())->get('/admin/room-control')->assertForbidden();
});

it('shows orga the receiver state and escapes receiver text', function () {
    fakeReceiver();

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/room-control')
        ->assertOk()
        ->assertSee('30,5')
        ->assertSee('Blu-ray')
        ->assertSee('Receiver ist an')
        ->assertDontSee('<b>Console</b>', false)
        ->assertSee('&lt;b&gt;Console&lt;/b&gt;', false);
});

it('renders the unreachable state with every control disabled', function () {
    fakeReceiver(connected: false);
    $this->actingAs(User::factory()->orga()->create());

    $html = Livewire::test(RoomControl::class)->assertOk()->assertSee('Receiver nicht erreichbar')->html();

    $tag = fn (string $needle): string => preg_match('/<(?:button|select|input)\\b[^>]*'.preg_quote($needle, '/').'[^>]*>/s', $html, $m) === 1 ? $m[0] : '';

    expect($tag('rc-range'))->toContain('disabled')
        ->and($tag('$wire.setSource'))->toContain('disabled')
        ->and($tag('$wire.setMode'))->toContain('disabled')
        ->and($tag('wire:click="toggleMute"'))->toContain('disabled="disabled"')
        ->and($tag('wire:click="togglePower"'))->toContain('disabled="disabled"');
});

it('disables volume, mute and source in standby but keeps power enabled', function () {
    fakeReceiver(power: false);
    $this->actingAs(User::factory()->orga()->create());

    $html = Livewire::test(RoomControl::class)->assertSee('Receiver ist aus')->html();

    $tag = fn (string $needle): string => preg_match('/<(?:button|select|input)\\b[^>]*'.preg_quote($needle, '/').'[^>]*>/s', $html, $m) === 1 ? $m[0] : '';

    expect($tag('rc-range'))->toContain('disabled')
        ->and($tag('$wire.setSource'))->toContain('disabled')
        ->and($tag('wire:click="toggleMute"'))->toContain('disabled="disabled"')
        ->and($tag('wire:click="volumeStep(1)"'))->toContain('disabled="disabled"')
        ->and($tag('wire:click="togglePower"'))->not->toContain('disabled="disabled"');
});

it('clamps a too-high volume to the ceiling before sending', function () {
    $fake = fakeReceiver();
    RoomAudioSetting::current()->update(['volume_ceiling' => 60]);
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RoomControl::class)->call('setVolume', 200);

    expect($fake->calls)->toBe([['setVolume', 60.0]]);
});

it('steps the volume by one', function () {
    $fake = fakeReceiver();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RoomControl::class)->call('volumeStep', 1)->call('volumeStep', -1);

    expect($fake->calls)->toBe([['setVolume', 31.5], ['setVolume', 29.5]]);
});

it('sets source, mode, mute and power through the actions', function () {
    $fake = fakeReceiver();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RoomControl::class)
        ->call('setSource', 'HDMI1')
        ->call('setMode', 'stereo')
        ->call('toggleMute')
        ->call('togglePower');

    expect($fake->calls)->toBe([
        ['setSource', 'HDMI1'], ['setMode', 'stereo'], ['setMute', true], ['setPower', false],
    ]);
});

it('persists the ceiling', function () {
    fakeReceiver();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RoomControl::class)->call('saveCeiling', 70)->assertNotified();

    expect(RoomAudioSetting::current()->volume_ceiling)->toBe(70);
});

it('shows a danger notification when the receiver is unavailable during an action', function () {
    $fake = fakeReceiver();
    $fake->willBeUnavailable();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RoomControl::class)
        ->call('setVolume', 20)
        ->assertNotified(Notification::make()->danger()->title(__('room_audio.errors.unavailable')))
        ->call('setSource', 'DVD')
        ->call('togglePower')
        ->assertOk();
});

it('shows a danger notification for invalid input instead of failing', function () {
    fakeReceiver();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RoomControl::class)
        ->call('setSource', 'BOGUS')
        ->assertNotified(Notification::make()->danger()->title(__('room_audio.errors.invalid')))
        ->call('saveCeiling', 500)
        ->assertOk();

    expect(RoomAudioSetting::current()->volume_ceiling)->toBe(60);
});

it('shows a danger notification for empty or non-numeric ceiling and volume', function () {
    $fake = fakeReceiver();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RoomControl::class)
        ->call('saveCeiling', null)
        ->assertNotified(Notification::make()->danger()->title(__('room_audio.errors.invalid')))
        ->call('saveCeiling', 'abc')
        ->call('setVolume', null)
        ->call('setVolume', 'loud')
        ->call('setVolume', NAN)
        ->assertOk();

    expect($fake->calls)->toBe([])
        ->and(RoomAudioSetting::current()->volume_ceiling)->toBe(60);
});

it('shows a danger notification when the sidecar rejects a command', function () {
    config(['services.room_audio.rest_url' => 'http://denon-admin.test', 'services.room_audio.token' => 't']);
    app()->forgetInstance(ReceiverClient::class);
    Http::fake([
        'denon-admin.test/state' => Http::response([
            'connected' => true, 'power' => true, 'volume' => 20, 'volumeMax' => 98, 'mute' => false,
            'source' => 'DVD', 'mode' => 'stereo', 'sources' => [], 'modes' => [],
        ], 200),
        'denon-admin.test/command' => Http::response(['detail' => 'invalid or missing bearer token'], 401),
    ]);
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RoomControl::class)
        ->call('setVolume', 20)
        ->assertNotified(Notification::make()->danger()->title(__('room_audio.errors.invalid')))
        ->assertOk();
});

it('selects the current mode and source, and shows a placeholder for unknown ones', function () {
    $fake = new FakeReceiverClient;
    $fake->willReturnState(new ReceiverState(
        connected: true, power: true, volume: 20.0, volumeMax: 98.0, mute: false,
        source: 'SAT/CBL', mode: '', sources: [['code' => 'DVD', 'name' => 'Blu-ray']],
        modes: [['key' => 'stereo', 'name' => 'Stereo']], modeName: 'Dolby Digital',
    ));
    app()->instance(ReceiverClient::class, $fake);
    $this->actingAs(User::factory()->orga()->create());

    $html = Livewire::test(RoomControl::class)->html();

    expect($html)->toContain('Dolby Digital')
        ->and($html)->toContain('SAT/CBL')
        ->and($html)->toMatch('/<option value="" selected disabled[^>]*>\s*Dolby Digital/s');
});

it('marks the matching mode option as selected', function () {
    fakeReceiver();
    $this->actingAs(User::factory()->orga()->create());

    expect(Livewire::test(RoomControl::class)->html())
        ->toMatch('/<option value="stereo"\s+selected[^>]*>\s*Stereo/s');
});
