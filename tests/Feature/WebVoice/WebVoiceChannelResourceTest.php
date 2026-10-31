<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\WebVoice\Actions\SetChannelArchived;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Pages\CreateWebVoiceChannel;
use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Pages\ListWebVoiceChannels;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Testing\FakeWebVoiceClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = new FakeWebVoiceClient;
    $this->app->instance(WebVoiceClient::class, $this->fake);
});

it('closes the channel list to participants and helpers', function (Role $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get('/admin/web-voice-channels')
        ->assertForbidden();
})->with([Role::Participant, Role::Helper]);

it('lets orga see the live rooms but not create, edit, archive or restore', function () {
    $channel = WebVoiceChannel::factory()->create(['name' => 'Lobby']);
    $this->actingAs(User::factory()->create(['role' => Role::Orga]));

    $this->get('/admin/web-voice-channels')->assertOk()->assertSee('Lobby');
    $this->get('/admin/web-voice-channels/create')->assertForbidden();
    $this->get("/admin/web-voice-channels/{$channel->id}/edit")->assertForbidden();

    Livewire::test(ListWebVoiceChannels::class)
        ->assertTableActionHidden('archive', $channel)
        ->assertTableActionHidden('restore', $channel);

    expect(fn () => app(SetChannelArchived::class)->handle(auth()->user(), $channel, true))
        ->toThrow(AuthorizationException::class);
});

it('lets an admin list the channels with live occupancy', function () {
    $lobby = WebVoiceChannel::factory()->create(['name' => 'Lobby']);
    $this->fake->join($lobby->roomName(), 7, 'Ada');

    $this->actingAs(User::factory()->create(['role' => Role::Admin]))
        ->get('/admin/web-voice-channels')
        ->assertOk()
        ->assertSee('Lobby')
        ->assertSee('Ada');
});

it('shows a note instead of failing when the voice server is down', function () {
    WebVoiceChannel::factory()->create(['name' => 'Lobby']);
    $this->fake->unavailable = true;

    $this->actingAs(User::factory()->create(['role' => Role::Admin]))
        ->get('/admin/web-voice-channels')
        ->assertOk()
        ->assertSee('Lobby')
        ->assertSee('Der Sprachserver antwortet nicht.');
});

it('creates a general channel through the action, so kind is never form-controlled', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    Livewire::test(CreateWebVoiceChannel::class)
        ->fillForm(['name' => 'Chill room', 'description' => 'Just talking', 'kind' => 'match'])
        ->call('create')
        ->assertHasNoFormErrors();

    $channel = WebVoiceChannel::query()->where('name', 'Chill room')->firstOrFail();

    expect($channel->kind)->toBe(ChannelKind::General)
        ->and($channel->game_match_id)->toBeNull();
});

it('archives and restores a channel', function () {
    $channel = WebVoiceChannel::factory()->create();
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    Livewire::test(ListWebVoiceChannels::class)->callTableAction('archive', $channel);
    expect($channel->fresh()->isArchived())->toBeTrue();

    Livewire::test(ListWebVoiceChannels::class)->callTableAction('restore', $channel);
    expect($channel->fresh()->isArchived())->toBeFalse();
});
