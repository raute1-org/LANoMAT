<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Notifications\Notifications\RegistrationConfirmed;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Models\WebVoiceMessage;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const NATIVE_V1 = '/api/voice-client/v1';

/** @return array<string, string> */
function nativeBearer(User $user): array
{
    $plain = 'lvc_'.Str::random(48);
    $token = new VoiceClientToken;
    $token->forceFill([
        'user_id' => $user->id,
        'device_name' => 'Test PC',
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->addDays(90),
    ])->save();

    test()->nativeToken = $token;

    return ['Authorization' => 'Bearer '.$plain];
}

beforeEach(function () {
    $this->lobby = WebVoiceChannel::factory()->create(['name' => 'Lobby']);
    $this->teamA = WebVoiceChannel::factory()->match()->create(['name' => 'Team A']);
    $this->teamB = WebVoiceChannel::factory()->match()->create(['name' => 'Team B']);
    $this->archived = WebVoiceChannel::factory()->archived()->create(['name' => 'Alt']);

    $this->ada = User::factory()->create(['name' => 'Ada']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
    $this->admin = User::factory()->create(['name' => 'Root', 'role' => Role::Admin]);
    $this->orga = User::factory()->create(['name' => 'Orga', 'role' => Role::Orga]);
    $this->teamA->members()->attach($this->ada);
    $this->teamB->members()->attach($this->bob);
});

function seedMessages(WebVoiceChannel $channel, User $author, int $count): void
{
    foreach (range(1, $count) as $i) {
        $m = new WebVoiceMessage;
        $m->web_voice_channel_id = $channel->id;
        $m->user_id = $author->id;
        $m->body = "msg {$i}";
        $m->save();
    }
}

describe('authentication', function () {
    it('answers 401 without, with garbage and with revoked tokens on every new route', function () {
        $routes = [
            ['GET', "/rooms/{$this->lobby->id}/messages"],
            ['POST', "/rooms/{$this->lobby->id}/messages"],
            ['DELETE', '/messages/1'],
            ['POST', '/broadcasting/auth'],
            ['GET', '/notifications'],
            ['GET', '/notifications/unread-count'],
            ['POST', '/notifications/read-all'],
            ['POST', '/notifications/'.Str::uuid().'/read'],
            ['GET', '/notification-preferences'],
            ['PUT', '/notification-preferences'],
        ];

        $headers = nativeBearer($this->ada);
        $this->nativeToken->forceFill(['revoked_at' => now()])->save();

        foreach ($routes as [$method, $path]) {
            $this->json($method, NATIVE_V1.$path)->assertUnauthorized();
            $this->json($method, NATIVE_V1.$path, [], ['Authorization' => 'Bearer garbage'])->assertUnauthorized();
            $this->json($method, NATIVE_V1.$path, [], $headers)->assertUnauthorized();
        }
    });

    it('does not accept a bearer token on the web routes', function () {
        $headers = nativeBearer($this->ada);

        $this->getJson("/talk/channels/{$this->lobby->id}/messages", $headers)->assertUnauthorized();
    });
});

describe('chat', function () {
    it('lists history newest page first with a cursor, like the browser', function () {
        config(['webvoice.chat.history' => 3]);
        seedMessages($this->lobby, $this->ada, 7);
        $headers = nativeBearer($this->bob);

        $first = $this->getJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", $headers)->assertOk();
        expect(collect($first->json('messages'))->pluck('body')->all())->toBe(['msg 5', 'msg 6', 'msg 7']);

        $second = $this->getJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages?before=".$first->json('next_before'), $headers)->assertOk();
        expect(collect($second->json('messages'))->pluck('body')->all())->toBe(['msg 2', 'msg 3', 'msg 4']);

        $last = $this->getJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages?before=".$second->json('next_before'), $headers)->assertOk();
        expect(collect($last->json('messages'))->pluck('body')->all())->toBe(['msg 1'])
            ->and($last->json('next_before'))->toBeNull();

        // Same page the browser serves.
        $browser = $this->actingAs($this->bob)->getJson("/talk/channels/{$this->lobby->id}/messages")->json('messages');
        expect($first->json('messages'))->toBe($browser);
    });

    it('carries only the message DTO the browser sees', function () {
        seedMessages($this->lobby, $this->ada, 1);

        $message = $this->getJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", nativeBearer($this->bob))->json('messages.0');

        expect(array_keys($message))->toBe(['id', 'channel_id', 'body', 'created_at', 'author'])
            ->and(array_keys($message['author']))->toBe(['id', 'name', 'avatar_url'])
            ->and($message['author']['name'])->toBe('Ada');
    });

    it('applies the browser visibility: unseen rooms are 404, own and admin rooms are readable', function () {
        seedMessages($this->teamA, $this->ada, 1);

        $this->getJson(NATIVE_V1."/rooms/{$this->teamA->id}/messages", nativeBearer($this->ada))->assertOk();
        $this->getJson(NATIVE_V1."/rooms/{$this->teamA->id}/messages", nativeBearer($this->admin))->assertOk();
        $this->getJson(NATIVE_V1."/rooms/{$this->teamA->id}/messages", nativeBearer($this->bob))->assertNotFound();
        $this->postJson(NATIVE_V1."/rooms/{$this->teamA->id}/messages", ['body' => 'x'], nativeBearer($this->bob))->assertNotFound();
        $this->getJson(NATIVE_V1.'/rooms/999999/messages', nativeBearer($this->bob))->assertNotFound();
        $this->getJson(NATIVE_V1."/rooms/{$this->archived->id}/messages", nativeBearer($this->ada))->assertNotFound();
        $this->getJson(NATIVE_V1."/rooms/{$this->archived->id}/messages", nativeBearer($this->admin))->assertStatus(410);
        expect(WebVoiceMessage::query()->where('body', 'x')->count())->toBe(0);
    });

    it('posts with the browser validation and returns the DTO', function () {
        $headers = nativeBearer($this->ada);

        $this->postJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", ['body' => '  hello  '], $headers)
            ->assertCreated()
            ->assertJsonPath('message.body', 'hello')
            ->assertJsonPath('message.author.id', $this->ada->id)
            ->assertJsonPath('message.channel_id', $this->lobby->id);

        $this->postJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", ['body' => ''], $headers)->assertUnprocessable();
        $this->postJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", ['body' => str_repeat('a', 501)], $headers)->assertUnprocessable();
        $this->postJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", ['body' => str_repeat('a', 500)], $headers)->assertCreated();
        // The author is the token's user, whatever the client sends.
        $this->postJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", ['body' => 'b', 'user_id' => $this->bob->id], $headers)
            ->assertCreated()->assertJsonPath('message.author.id', $this->ada->id);
    });

    it('rate limits per user, sharing the budget with the browser', function () {
        config(['webvoice.chat.rate_limit' => 3, 'webvoice.chat.rate_window' => 60]);
        RateLimiter::clear('web-voice-chat|'.$this->ada->id);
        $headers = nativeBearer($this->ada);

        foreach (range(1, 2) as $i) {
            $this->postJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", ['body' => "m{$i}"], $headers)->assertCreated();
        }
        $this->actingAs($this->ada)->postJson("/talk/channels/{$this->lobby->id}/messages", ['body' => 'web'])->assertCreated();
        $this->postJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", ['body' => 'm4'], $headers)->assertStatus(429);

        // Another user keeps their own budget.
        $this->postJson(NATIVE_V1."/rooms/{$this->lobby->id}/messages", ['body' => 'hi'], nativeBearer($this->bob))->assertCreated();
    });

    it('lets only moderators delete messages, with the browser rules', function () {
        seedMessages($this->lobby, $this->ada, 1);
        $message = WebVoiceMessage::query()->firstOrFail();

        $this->deleteJson(NATIVE_V1."/messages/{$message->id}", [], nativeBearer($this->bob))->assertForbidden();
        $this->deleteJson(NATIVE_V1."/messages/{$message->id}", [], nativeBearer($this->ada))->assertForbidden();
        expect(WebVoiceMessage::query()->count())->toBe(1);

        $this->deleteJson(NATIVE_V1."/messages/{$message->id}", [], nativeBearer($this->orga))->assertOk();
        expect(WebVoiceMessage::query()->count())->toBe(0);

        seedMessages($this->teamA, $this->ada, 1);
        $other = WebVoiceMessage::query()->firstOrFail();
        $this->deleteJson(NATIVE_V1."/messages/{$other->id}", [], nativeBearer($this->admin))->assertOk();
        $this->deleteJson(NATIVE_V1.'/messages/999999', [], nativeBearer($this->admin))->assertNotFound();
    });

    it('does not reveal messages of an unseen room through deletion', function () {
        seedMessages($this->teamA, $this->ada, 1);
        $message = WebVoiceMessage::query()->firstOrFail();

        // Bob cannot see Team A: 404, not the 403 that would confirm the message exists.
        $this->deleteJson(NATIVE_V1."/messages/{$message->id}", [], nativeBearer($this->bob))->assertNotFound();
    });
});

describe('realtime', function () {
    beforeEach(function () {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'ws.example.test',
            'broadcasting.connections.reverb.options.port' => 443,
            'broadcasting.connections.reverb.options.scheme' => 'https',
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');
    });

    function nativeChannelAuth(object $test, array $headers, string $channel)
    {
        return $test->postJson(NATIVE_V1.'/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel], $headers);
    }

    it('signs the chat channel the browser would sign, for the bearer user only', function () {
        nativeChannelAuth($this, nativeBearer($this->ada), "private-web-voice.channel.{$this->teamA->id}")
            ->assertOk()->assertJsonStructure(['auth']);
        nativeChannelAuth($this, nativeBearer($this->ada), "private-web-voice.channel.{$this->lobby->id}")->assertOk();
        nativeChannelAuth($this, nativeBearer($this->ada), 'private-web-voice.directory')->assertOk();
        nativeChannelAuth($this, nativeBearer($this->admin), "private-web-voice.channel.{$this->teamB->id}")->assertOk();
    });

    it('answers 403 for channels the user may not see, unknown channels and non-private names', function () {
        $ada = nativeBearer($this->ada);

        nativeChannelAuth($this, $ada, "private-web-voice.channel.{$this->teamB->id}")->assertForbidden();
        nativeChannelAuth($this, $ada, "private-web-voice.channel.{$this->archived->id}")->assertForbidden();
        nativeChannelAuth($this, $ada, 'private-web-voice.channel.999999')->assertForbidden();
        nativeChannelAuth($this, $ada, 'private-something.else')->assertForbidden();
        nativeChannelAuth($this, $ada, 'private-App.Models.User.'.$this->bob->id)->assertForbidden();
        nativeChannelAuth($this, $ada, 'presence-web-voice.directory')->assertForbidden();
        nativeChannelAuth($this, $ada, "web-voice.channel.{$this->lobby->id}")->assertForbidden();
        $this->postJson(NATIVE_V1.'/broadcasting/auth', [], $ada)->assertForbidden();
    });

    it('signs the own notifications channel', function () {
        nativeChannelAuth($this, nativeBearer($this->ada), 'private-App.Models.User.'.$this->ada->id)->assertOk()->assertJsonStructure(['auth']);
    });

    it('produces a signature for the requested socket and channel', function () {
        $auth = nativeChannelAuth($this, nativeBearer($this->ada), 'private-web-voice.directory')->json('auth');

        $expected = 'test-key:'.hash_hmac('sha256', '123.456:private-web-voice.directory', 'test-secret');
        expect($auth)->toBe($expected);
    });

    it('exposes the public Reverb connection info in config and never the secret', function () {
        $response = $this->getJson(NATIVE_V1.'/config', nativeBearer($this->ada))->assertOk()
            ->assertJsonPath('api_version', 1)
            ->assertJsonPath('realtime.driver', 'reverb')
            ->assertJsonPath('realtime.key', 'test-key')
            ->assertJsonPath('realtime.host', 'ws.example.test')
            ->assertJsonPath('realtime.port', 443)
            ->assertJsonPath('realtime.scheme', 'https')
            ->assertJsonPath('realtime.auth_endpoint', '/api/voice-client/v1/broadcasting/auth')
            ->assertJsonStructure(['livekit_url', 'voice_ready', 'audio']);

        expect($response->getContent())->not->toContain('test-secret')->not->toContain('test-app');
    });

    it('reports realtime null when Reverb is not configured', function () {
        config(['broadcasting.default' => 'null']);

        $this->getJson(NATIVE_V1.'/config', nativeBearer($this->ada))->assertOk()->assertJsonPath('realtime', null);
    });
});

describe('notifications', function () {
    function notify(User $user, string $event = 'Fest'): string
    {
        $user->notify(new RegistrationConfirmed($event));

        return (string) $user->notifications()->latest('created_at')->latest('id')->firstOrFail()->id;
    }

    it('lists own notifications, unread first, with a filter and paging', function () {
        $readId = notify($this->ada, 'Old');
        $this->ada->notifications()->findOrFail($readId)->markAsRead();
        notify($this->ada, 'New 1');
        notify($this->ada, 'New 2');
        notify($this->bob, 'Bobs');
        $headers = nativeBearer($this->ada);

        $all = $this->getJson(NATIVE_V1.'/notifications', $headers)->assertOk();
        expect($all->json('total'))->toBe(3)
            ->and(collect($all->json('notifications'))->pluck('read')->all())->toBe([false, false, true])
            ->and($all->json('notifications.0'))->toHaveKeys(['id', 'category', 'title', 'body', 'read', 'read_at', 'created_at'])
            ->and($all->json('notifications.0.category'))->toBe('registration');

        $unread = $this->getJson(NATIVE_V1.'/notifications?unread=1', $headers)->assertOk();
        expect($unread->json('total'))->toBe(2);

        $paged = $this->getJson(NATIVE_V1.'/notifications?per_page=2&page=2', $headers)->assertOk();
        expect($paged->json('notifications'))->toHaveCount(1)->and($paged->json('has_more'))->toBeFalse();
        $this->getJson(NATIVE_V1.'/notifications?per_page=2', $headers)->assertJsonPath('has_more', true);
    });

    it('counts unread notifications', function () {
        notify($this->ada);
        $id = notify($this->ada);
        notify($this->bob);
        $this->ada->notifications()->findOrFail($id)->markAsRead();

        $this->getJson(NATIVE_V1.'/notifications/unread-count', nativeBearer($this->ada))->assertOk()->assertExactJson(['unread' => 1]);
    });

    it('marks only own notifications read; foreign and unknown ids are 404', function () {
        $mine = notify($this->ada);
        $bobs = notify($this->bob);
        $headers = nativeBearer($this->ada);

        $this->postJson(NATIVE_V1."/notifications/{$bobs}/read", [], $headers)->assertNotFound();
        $this->postJson(NATIVE_V1.'/notifications/'.Str::uuid().'/read', [], $headers)->assertNotFound();
        expect($this->bob->unreadNotifications()->count())->toBe(1);

        $this->postJson(NATIVE_V1."/notifications/{$mine}/read", [], $headers)->assertOk()
            ->assertJsonPath('notification.read', true);
        expect($this->ada->unreadNotifications()->count())->toBe(0);
    });

    it('marks all own notifications read and leaves the others alone', function () {
        notify($this->ada);
        notify($this->ada);
        notify($this->bob);

        $this->postJson(NATIVE_V1.'/notifications/read-all', [], nativeBearer($this->ada))->assertOk()->assertExactJson(['marked' => 2]);

        expect($this->ada->unreadNotifications()->count())->toBe(0)
            ->and($this->bob->unreadNotifications()->count())->toBe(1);
    });

    it('reads and updates the category preferences with the same model the channels use', function () {
        $headers = nativeBearer($this->ada);

        $defaults = $this->getJson(NATIVE_V1.'/notification-preferences', $headers)->assertOk()->json('preferences');
        expect($defaults['registration'])->toBeTrue()->and($defaults)->not->toHaveKeys(['system', 'account']);

        $this->putJson(NATIVE_V1.'/notification-preferences', ['preferences' => ['registration' => false]], $headers)
            ->assertOk()->assertJsonPath('preferences.registration', false)->assertJsonPath('preferences.match', true);

        expect($this->ada->fresh()->notification_prefs)->toBe(['registration' => false]);

        // The opted-out category no longer reaches the bell (same gate as the browser path).
        $this->ada->fresh()->notify(new RegistrationConfirmed('Fest'));
        expect($this->ada->notifications()->count())->toBe(0);

        // Other categories keep their value on a partial update.
        $this->putJson(NATIVE_V1.'/notification-preferences', ['preferences' => ['match' => false]], $headers)->assertOk();
        expect($this->ada->fresh()->notification_prefs)->toEqual(['registration' => false, 'match' => false]);
    });

    it('rejects unknown categories and non-boolean values, and never touches other users', function () {
        $headers = nativeBearer($this->ada);

        $this->putJson(NATIVE_V1.'/notification-preferences', ['preferences' => ['system' => false]], $headers)->assertUnprocessable();
        $this->putJson(NATIVE_V1.'/notification-preferences', ['preferences' => ['match' => 'maybe']], $headers)->assertUnprocessable();
        $this->putJson(NATIVE_V1.'/notification-preferences', [], $headers)->assertUnprocessable();
        $this->putJson(NATIVE_V1.'/notification-preferences', ['preferences' => ['match' => false], 'user_id' => $this->bob->id], $headers)->assertOk();

        expect($this->bob->fresh()->notification_prefs ?? [])->toBe([]);
    });
});
