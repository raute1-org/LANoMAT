<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Voice\Domain\VoiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeVoice(['mumble', 'teamspeak']);
    config([
        'services.mumble.host' => 'voice.example.test',
        'services.mumble.port' => 64738,
        'services.teamspeak.host' => 'ts.example.test',
        'services.teamspeak.port' => 9987,
        'services.voice.default_provider' => 'mumble',
    ]);
});

function startEightEntrySingleElimForVoiceLinks(): Tournament
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(8)->create(['tournament_id' => $tournament->id]);

    return app(StartTournament::class)->handle($tournament)->fresh();
}

it('lists every provider present in voice_channels, marking the config default as default', function () {
    $tournament = startEightEntrySingleElimForVoiceLinks();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $viewer = User::find($entry1->user_id);

    $match->update(['voice_channels' => [
        'mumble' => ['entry1_channel_id' => 101, 'entry2_channel_id' => 102],
        'teamspeak' => ['entry1_channel_id' => 201, 'entry2_channel_id' => 202],
    ]]);

    $this->actingAs($viewer)
        ->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->has('myMatchVoiceLinks', 2)
            ->where('myMatchVoiceLinks.0.provider', 'mumble')
            ->where('myMatchVoiceLinks.0.label', 'Mumble')
            ->where('myMatchVoiceLinks.0.url', 'mumble://voice.example.test:64738/'.$entry1->display_name)
            ->where('myMatchVoiceLinks.0.isDefault', true)
            // Occupancy (issue #13): the fake's channel 101 was never
            // seeded via setOccupants(), so it reports 0 — proving the
            // absence of real sidecars (mode A) degrades to a plain,
            // non-crashing zero rather than an error.
            ->where('myMatchVoiceLinks.0.occupants', 0)
            ->where('myMatchVoiceLinks.1.provider', 'teamspeak')
            ->where('myMatchVoiceLinks.1.label', 'TeamSpeak')
            ->where('myMatchVoiceLinks.1.url', 'ts3server://ts.example.test?port=9987&channel='.rawurlencode($entry1->display_name))
            ->where('myMatchVoiceLinks.1.isDefault', false)
            ->where('myMatchVoiceLinks.1.occupants', 0)
        );
});

it('marks the viewer team voice_provider choice as default instead of the config default', function () {
    config(['services.voice.default_provider' => 'mumble']);

    $tournament = Tournament::factory()->for(Event::factory()->live())->checkIn()->create(['team_size' => 2]);
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id, 'voice_provider' => VoiceProvider::TeamSpeak]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id]);

    $entry = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'user_id' => null]);
    $opponent = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id]);

    $match = GameMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'entry1_id' => $entry->id,
        'entry2_id' => $opponent->id,
        'round' => 1,
        'voice_channels' => [
            'mumble' => ['entry1_channel_id' => 1, 'entry2_channel_id' => 2],
            'teamspeak' => ['entry1_channel_id' => 3, 'entry2_channel_id' => 4],
        ],
    ]);

    $this->actingAs($owner)
        ->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->has('myMatchVoiceLinks', 2)
            ->where('myMatchVoiceLinks.0.provider', 'mumble')
            ->where('myMatchVoiceLinks.0.isDefault', false)
            ->where('myMatchVoiceLinks.1.provider', 'teamspeak')
            ->where('myMatchVoiceLinks.1.isDefault', true)
        );
});

it('surfaces the live occupant count reported by the provider fake for the viewer channel', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = startEightEntrySingleElimForVoiceLinks();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $viewer = User::find($entry1->user_id);

    $mumbleChannel = $fakes['mumble']->createChannel($entry1->display_name, null, true);
    $fakes['mumble']->setOccupants($mumbleChannel->id, 4);

    $match->update(['voice_channels' => [
        'mumble' => ['entry1_channel_id' => $mumbleChannel->id, 'entry2_channel_id' => 999],
    ]]);

    $this->actingAs($viewer)
        ->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->where('myMatchVoiceLinks.0.provider', 'mumble')
            ->where('myMatchVoiceLinks.0.occupants', 4)
        );
});

it('yields an empty myMatchVoiceLinks array when no channels are provisioned', function () {
    $tournament = startEightEntrySingleElimForVoiceLinks();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $viewer = User::find($entry1->user_id);

    $match->update(['voice_channels' => null]);

    $this->actingAs($viewer)
        ->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->where('myMatchVoiceLinks', [])
        );
});
