<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Filament\Resources\ServerLinks\Pages\ListServerLinks;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\RepositoryEggs;
use Database\Seeders\GamesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([PollServerStatusJob::class]);
    config(['services.pelican.owner_user_id' => '3', 'services.pelican.max_ram_mb' => 4096, 'services.pelican.steam_web_api_key' => null]);
    $this->fake = fakePelican();
    $this->fake->eggs['9'] = new PelicanEgg('9', 'Garrys Mod (LANoMAT)', ['GAMEMODE' => 'sandbox', 'SRCDS_MAP' => 'x', 'MAX_PLAYERS' => '32', 'SERVER_HOSTNAME' => 'x'], uuid: RepositoryEggs::GMOD_UUID);
    $this->seed(GamesSeeder::class);
    $this->event = Event::factory()->create();
    $this->game = Game::query()->where('slug', 'garrys-mod')->firstOrFail();
});

it('starts the TTT preset for an event from the server links page', function () {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListServerLinks::class)
        ->callAction('startFromPreset', [
            'event_id' => $this->event->id,
            'game_id' => $this->game->id,
            'preset_key' => 'ttt',
            'title' => 'Sommer TTT',
            'overrides' => ['SRCDS_MAP' => 'ttt_minecraft_b5', 'WORKSHOP_ID' => ''],
        ])
        ->assertNotified(__('gameservers.start_preset.started'));

    $link = ServerLink::query()->sole();
    $env = $this->fake->created[0]['spec']->environment;

    expect($link->event_id)->toBe($this->event->id)
        ->and($link->title)->toBe('Sommer TTT')
        ->and($env['SRCDS_MAP'])->toBe('ttt_minecraft_b5')
        ->and($env['GAMEMODE'])->toBe('terrortown');
});

it('does not accept a locked preset in the form and creates nothing', function () {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListServerLinks::class)
        ->callAction('startFromPreset', [
            'event_id' => $this->event->id,
            'game_id' => $this->game->id,
            'preset_key' => 'prop-hunt',
        ])
        ->assertHasActionErrors(['preset_key']);

    $this->fake->assertNothingCreated();
});

it('offers the action to orga', function () {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListServerLinks::class)->assertActionVisible('startFromPreset');
});
