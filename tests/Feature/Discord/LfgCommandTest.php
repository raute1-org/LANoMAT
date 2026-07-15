<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.discord.application_id' => 'app-123']);
});

it('lists the current event active lfg posts for /lfg list', function () {
    $event = Event::factory()->live()->create();
    $poster = User::factory()->create(['name' => 'Alice']);
    LfgPost::factory()->for($event)->for($poster)->create([
        'title' => 'Suche Mitspieler für Ranked',
        'game' => 'Valorant',
    ]);
    LfgPost::factory()->for($event)->expired()->create([
        'title' => 'Abgelaufene Anzeige',
    ]);

    $body = applicationCommand('lfg', [
        ['name' => 'list', 'type' => 1, 'options' => []],
    ]);

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', fn ($content) => str_contains($content, 'Suche Mitspieler für Ranked')
            && str_contains($content, 'Valorant')
            && ! str_contains($content, 'Abgelaufene Anzeige')
        );
});

it('returns the German no-event fallback for /lfg list with no publicly visible event', function () {
    Event::factory()->draft()->create();

    $body = applicationCommand('lfg', [
        ['name' => 'list', 'type' => 1, 'options' => []],
    ]);

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.commands.lfg.list.no_current_event'));
});

it('returns the German no-posts fallback for /lfg list when there are no active posts', function () {
    Event::factory()->live()->create();

    $body = applicationCommand('lfg', [
        ['name' => 'list', 'type' => 1, 'options' => []],
    ]);

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.commands.lfg.list.none'));
});

it('creates a post and confirms for /lfg create from a mapped discord user', function () {
    $user = User::factory()->create(['discord_id' => '111222333']);
    $event = Event::factory()->live()->create();

    $body = applicationCommand('lfg', [
        ['name' => 'create', 'type' => 1, 'options' => [
            ['name' => 'title', 'type' => 3, 'value' => 'Suche 4. Mann für Ranked'],
            ['name' => 'game', 'type' => 3, 'value' => 'CS2'],
        ]],
    ], discordUserId: '111222333');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4);

    expect(LfgPost::count())->toBe(1);

    $post = LfgPost::first();
    expect($post->event_id)->toBe($event->id)
        ->and($post->user_id)->toBe($user->id)
        ->and($post->title)->toBe('Suche 4. Mann für Ranked')
        ->and($post->game)->toBe('CS2');
});

it('does not create anything and returns the German link-account message for /lfg create from an unmapped discord user', function () {
    Event::factory()->live()->create();

    $body = applicationCommand('lfg', [
        ['name' => 'create', 'type' => 1, 'options' => [
            ['name' => 'title', 'type' => 3, 'value' => 'Suche Mitspieler'],
        ]],
    ], discordUserId: 'unmapped-discord-id');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.not_linked'));

    expect(LfgPost::count())->toBe(0);
});

it('returns the German invalid-title message for /lfg create with a title over 120 characters and creates no row', function () {
    User::factory()->create(['discord_id' => '777888999']);
    Event::factory()->live()->create();

    $body = applicationCommand('lfg', [
        ['name' => 'create', 'type' => 1, 'options' => [
            ['name' => 'title', 'type' => 3, 'value' => str_repeat('a', 121)],
        ]],
    ], discordUserId: '777888999');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('lfg.errors.invalid_title'));

    expect(LfgPost::count())->toBe(0);
});

it('returns the German no-event fallback for /lfg create with no publicly visible event', function () {
    User::factory()->create(['discord_id' => '444555666']);

    $body = applicationCommand('lfg', [
        ['name' => 'create', 'type' => 1, 'options' => [
            ['name' => 'title', 'type' => 3, 'value' => 'Suche Mitspieler'],
        ]],
    ], discordUserId: '444555666');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.commands.lfg.create.no_current_event'));

    expect(LfgPost::count())->toBe(0);
});
