<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Events\MessageDeleted;
use App\Modules\WebVoice\Events\MessagePosted;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Models\WebVoiceMessage;
use App\Modules\WebVoice\Models\WebVoiceMute;
use App\Modules\WebVoice\Testing\FakeWebVoiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'webvoice.url' => 'wss://voice.example.test',
        'webvoice.api_key' => 'k',
        'webvoice.api_secret' => 'a-long-enough-secret-for-testing-0123456789',
        'webvoice.room_prefix' => 'lanomat-',
    ]);

    $this->fake = new FakeWebVoiceClient;
    $this->app->instance(WebVoiceClient::class, $this->fake);

    $this->lobby = WebVoiceChannel::factory()->create(['name' => 'Lobby']);
    $this->teamA = WebVoiceChannel::factory()->match()->create(['name' => 'Team A']);
    $this->teamB = WebVoiceChannel::factory()->match()->create(['name' => 'Team B']);

    $this->ada = User::factory()->create(['name' => 'Ada']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
    $this->admin = User::factory()->create(['role' => Role::Admin]);
    $this->orga = User::factory()->create(['role' => Role::Orga]);
    $this->helper = User::factory()->create(['role' => Role::Helper]);

    $this->teamA->members()->attach($this->ada);
    $this->teamB->members()->attach($this->bob);
});

/* ---- page ------------------------------------------------------------ */

it('sends guests to the login', function () {
    $this->get('/talk')->assertRedirect('/login');
    $this->postJson("/talk/channels/{$this->lobby->id}/token")->assertUnauthorized();
});

it('renders only the channels the viewer may see', function () {
    $this->actingAs($this->ada)->get('/talk')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('WebVoice/Index')
            ->where('channels.0.name', 'Lobby')
            ->where('channels.1.name', 'Team A')
            ->has('channels', 2)
            ->where('canModerate', false)
            ->where('voiceReady', true));
});

it('shows an admin every channel and the moderation flag', function () {
    $this->actingAs($this->admin)->get('/talk')
        ->assertInertia(fn ($page) => $page->has('channels', 3)->where('canModerate', true));
});

it('reports the voice server as not set up while the chat still works', function () {
    config(['webvoice.url' => null]);

    $this->actingAs($this->ada)->get('/talk')->assertInertia(fn ($page) => $page->where('voiceReady', false));
});

/* ---- token ----------------------------------------------------------- */

it('issues a token for exactly the caller and the channel room, never a client-supplied identity', function () {
    $response = $this->actingAs($this->ada)->postJson("/talk/channels/{$this->teamA->id}/token", ['identity' => 'u999', 'room' => 'other']);

    $response->assertOk()->assertJsonPath('url', 'wss://voice.example.test')
        ->assertJsonPath('identity', 'u'.$this->ada->id)
        ->assertJsonPath('audio.bitrate', 24000)
        ->assertJsonPath('audio.dtx', true);

    expect($this->fake->issued)->toBe([[
        'room' => 'lanomat-'.$this->teamA->id,
        'identity' => 'u'.$this->ada->id,
        'name' => 'Ada',
        'canPublish' => true,
    ]]);
});

it('refuses a token for the opposing team room', function () {
    $this->actingAs($this->ada)->postJson("/talk/channels/{$this->teamB->id}/token")->assertForbidden();

    expect($this->fake->issued)->toBe([]);
});

it('lets an admin into any room', function () {
    $this->actingAs($this->admin)->postJson("/talk/channels/{$this->teamB->id}/token")->assertOk();
});

it('refuses a token for an archived channel, even for an admin', function () {
    $this->lobby->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->ada)->postJson("/talk/channels/{$this->lobby->id}/token")->assertForbidden();
    $this->actingAs($this->admin)->postJson("/talk/channels/{$this->lobby->id}/token")->assertStatus(410);
});

it('issues a token without publish rights to someone a moderator muted', function () {
    $mute = new WebVoiceMute;
    $mute->forceFill(['web_voice_channel_id' => $this->lobby->id, 'user_id' => $this->ada->id, 'muted_by' => $this->admin->id])->save();

    $this->actingAs($this->ada)->postJson("/talk/channels/{$this->lobby->id}/token")
        ->assertOk()->assertJsonPath('muted', true);

    expect($this->fake->issued[0]['canPublish'])->toBeFalse();
});

it('answers 503 with a German message when the server is not configured', function () {
    config(['webvoice.url' => '']);

    $this->actingAs($this->ada)->postJson("/talk/channels/{$this->lobby->id}/token")
        ->assertStatus(503)->assertJsonPath('message', 'Der Sprachserver ist gerade nicht erreichbar.');
});

/* ---- chat ------------------------------------------------------------ */

it('posts a message as the caller and pushes it to the channel', function () {
    Event::fake([MessagePosted::class]);

    $this->actingAs($this->ada)->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => '  Hallo zusammen  ', 'user_id' => $this->bob->id])
        ->assertCreated()
        ->assertJsonPath('message.body', 'Hallo zusammen')
        ->assertJsonPath('message.author.id', $this->ada->id)
        ->assertJsonPath('message.author.name', 'Ada');

    expect(WebVoiceMessage::query()->count())->toBe(1)
        ->and(WebVoiceMessage::query()->first()->user_id)->toBe($this->ada->id);
    Event::assertDispatched(MessagePosted::class, fn (MessagePosted $e) => $e->broadcastOn()->name === 'private-web-voice.channel.'.$this->lobby->id);
});

it('stores the message even when broadcasting fails', function () {
    Event::listen(MessagePosted::class, fn () => throw new RuntimeException('reverb down'));

    $this->actingAs($this->ada)->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => 'Hi'])->assertCreated();

    expect(WebVoiceMessage::query()->count())->toBe(1);
});

it('rejects empty, blank and too long messages', function () {
    $this->actingAs($this->ada);

    $this->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => ''])->assertUnprocessable();
    $this->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => '   '])->assertUnprocessable();
    $this->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => str_repeat('x', 501)])->assertUnprocessable();
    $this->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => str_repeat('ä', 500)])->assertCreated();
});

it('keeps markup as inert text', function () {
    $this->actingAs($this->ada)->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => '<script>alert(1)</script>'])
        ->assertCreated()->assertJsonPath('message.body', '<script>alert(1)</script>');
});

it('refuses to read or write in a room of the other team', function () {
    $this->actingAs($this->ada);

    $this->getJson("/talk/channels/{$this->teamB->id}/messages")->assertForbidden();
    $this->postJson("/talk/channels/{$this->teamB->id}/messages", ['body' => 'hi'])->assertForbidden();
    expect(WebVoiceMessage::query()->count())->toBe(0);
});

it('rate limits chat per user', function () {
    config(['webvoice.chat.rate_limit' => 3, 'webvoice.chat.rate_window' => 60]);
    $this->actingAs($this->ada);

    foreach (range(1, 3) as $i) {
        $this->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => "m{$i}"])->assertCreated();
    }

    $this->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => 'm4'])->assertStatus(429);

    // A different user has their own budget.
    $this->actingAs($this->bob)->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => 'hello'])->assertCreated();
});

it('serves history oldest first, paged with before', function () {
    config(['webvoice.chat.history' => 3]);
    foreach (range(1, 5) as $i) {
        $message = new WebVoiceMessage;
        $message->forceFill(['web_voice_channel_id' => $this->lobby->id, 'user_id' => $this->ada->id, 'body' => "m{$i}"])->save();
    }

    $this->actingAs($this->ada);
    $first = $this->getJson("/talk/channels/{$this->lobby->id}/messages")->assertOk()->json('messages');

    expect(array_column($first, 'body'))->toBe(['m3', 'm4', 'm5']);

    $older = $this->getJson("/talk/channels/{$this->lobby->id}/messages?before={$first[0]['id']}")->json('messages');

    expect(array_column($older, 'body'))->toBe(['m1', 'm2']);
});

it('refuses history of an archived channel with 410 for an admin', function () {
    $this->lobby->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->admin)->getJson("/talk/channels/{$this->lobby->id}/messages")->assertStatus(410);
    $this->actingAs($this->admin)->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => 'x'])->assertStatus(410);
});

/* ---- moderation ------------------------------------------------------ */

it('lets only an admin delete a message, and tells the channel', function () {
    $message = new WebVoiceMessage;
    $message->forceFill(['web_voice_channel_id' => $this->lobby->id, 'user_id' => $this->ada->id, 'body' => 'rude'])->save();

    $this->actingAs($this->ada)->deleteJson("/talk/messages/{$message->id}")->assertForbidden();
    expect(WebVoiceMessage::query()->count())->toBe(1);

    Event::fake([MessageDeleted::class]);
    $this->actingAs($this->admin)->deleteJson("/talk/messages/{$message->id}")->assertOk();

    expect(WebVoiceMessage::query()->count())->toBe(0);
    Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $e) => $e->messageId === $message->id && $e->channelId === $this->lobby->id);
});

it('lets only an admin mute, stores it for later joins and pushes it live', function () {
    $this->actingAs($this->bob)->postJson("/talk/channels/{$this->lobby->id}/participants/{$this->ada->id}/mute")->assertForbidden();
    expect(WebVoiceMute::query()->count())->toBe(0);

    $this->actingAs($this->admin)->postJson("/talk/channels/{$this->lobby->id}/participants/{$this->ada->id}/mute")
        ->assertOk()->assertJsonPath('live', true);

    expect(WebVoiceMute::query()->count())->toBe(1)
        ->and($this->fake->publishChanges)->toBe([['room' => 'lanomat-'.$this->lobby->id, 'identity' => 'u'.$this->ada->id, 'canPublish' => false]]);

    $this->actingAs($this->admin)->deleteJson("/talk/channels/{$this->lobby->id}/participants/{$this->ada->id}/mute")->assertOk();

    expect(WebVoiceMute::query()->count())->toBe(0)
        ->and($this->fake->publishChanges[1]['canPublish'])->toBeTrue();
});

it('keeps the mute when the server cannot be reached and says it was not applied live', function () {
    $this->fake->unavailable = true;

    $this->actingAs($this->admin)->postJson("/talk/channels/{$this->lobby->id}/participants/{$this->ada->id}/mute")
        ->assertOk()->assertJsonPath('live', false);

    expect(WebVoiceMute::query()->count())->toBe(1);
});

it('lets only an admin remove a participant', function () {
    $this->actingAs($this->ada)->postJson("/talk/channels/{$this->lobby->id}/participants/{$this->bob->id}/remove")->assertForbidden();
    expect($this->fake->removed)->toBe([]);

    $this->actingAs($this->admin)->postJson("/talk/channels/{$this->lobby->id}/participants/{$this->bob->id}/remove")->assertOk();

    expect($this->fake->removed)->toBe([['room' => 'lanomat-'.$this->lobby->id, 'identity' => 'u'.$this->bob->id]]);
});

it('answers 503 when removing while the server is down', function () {
    $this->fake->unavailable = true;

    $this->actingAs($this->admin)->postJson("/talk/channels/{$this->lobby->id}/participants/{$this->bob->id}/remove")->assertStatus(503);
});

/* ---- orga and helper -------------------------------------------------- */

it('gives orga the same view and moderation as an admin, and helpers none', function () {
    $this->actingAs($this->orga)->get('/talk')
        ->assertInertia(fn ($page) => $page->has('channels', 3)->where('canModerate', true));
    $this->actingAs($this->helper)->get('/talk')
        ->assertInertia(fn ($page) => $page->has('channels', 1)->where('canModerate', false));

    $this->actingAs($this->orga)->postJson("/talk/channels/{$this->teamB->id}/token")->assertOk();
    $this->actingAs($this->helper)->postJson("/talk/channels/{$this->teamB->id}/token")->assertForbidden();

    $this->actingAs($this->orga)->postJson("/talk/channels/{$this->lobby->id}/participants/{$this->ada->id}/mute")->assertOk();
    $this->actingAs($this->orga)->postJson("/talk/channels/{$this->lobby->id}/participants/{$this->ada->id}/remove")->assertOk();
    $this->actingAs($this->helper)->postJson("/talk/channels/{$this->lobby->id}/participants/{$this->ada->id}/remove")->assertForbidden();

    $message = new WebVoiceMessage;
    $message->forceFill(['web_voice_channel_id' => $this->lobby->id, 'user_id' => $this->ada->id, 'body' => 'x'])->save();
    $this->actingAs($this->helper)->deleteJson("/talk/messages/{$message->id}")->assertForbidden();
    $this->actingAs($this->orga)->deleteJson("/talk/messages/{$message->id}")->assertOk();
});

it('still refuses an archived channel for orga', function () {
    $this->lobby->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->orga)->postJson("/talk/channels/{$this->lobby->id}/token")->assertStatus(403);
});
