<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\Lfg\Events\LfgPostCreated;
use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $this->fake);
    config(['services.discord.announce_channel_id' => 'announce-1']);
});

it('announces once when a new LFG post is created', function () {
    $post = LfgPost::factory()->create(['title' => 'Suche Mitspieler für Ranked']);

    event(new LfgPostCreated($post));

    $this->fake->assertMessageSent('announce-1', 'Suche Mitspieler für Ranked');
    expect(collect($this->fake->messages))->toHaveCount(1);
});

it('does not announce twice for the same post', function () {
    $post = LfgPost::factory()->create();

    event(new LfgPostCreated($post));
    event(new LfgPostCreated($post));

    expect(collect($this->fake->messages))->toHaveCount(1);
});

it('does nothing when no announce channel is configured', function () {
    config(['services.discord.announce_channel_id' => null]);
    $post = LfgPost::factory()->create();

    event(new LfgPostCreated($post));

    $this->fake->assertNothingSent();
});
