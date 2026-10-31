<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Policies\WebVoiceChannelPolicy;
use App\Modules\WebVoice\Queries\VisibleChannels;
use App\Modules\WebVoice\Testing\FakeWebVoiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

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
});

it('lets only admin and orga run the latency test (policy matrix)', function (Role $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('latencyTest', WebVoiceChannel::class))->toBe($allowed);
})->with([
    'admin' => [Role::Admin, true],
    'orga' => [Role::Orga, true],
    'helper' => [Role::Helper, false],
    'participant' => [Role::Participant, false],
]);

it('answers the policy ability itself for orga only (admins pass the app-wide Gate::before)', function () {
    $policy = new WebVoiceChannelPolicy;

    expect($policy->latencyTest(User::factory()->make(['role' => Role::Orga])))->toBeTrue()
        ->and($policy->latencyTest(User::factory()->make(['role' => Role::Participant])))->toBeFalse();
});

it('issues two probe tokens with different identities in a reserved room for orga', function () {
    $orga = User::factory()->create(['role' => Role::Orga, 'name' => 'Olga']);

    $this->actingAs($orga)->postJson('/talk/latency-test/token', ['identity' => 'u999', 'room' => 'lanomat-1'])
        ->assertOk()
        ->assertJsonPath('url', 'wss://voice.example.test')
        ->assertJsonPath('sender.identity', 'u'.$orga->id.'-probe-tx')
        ->assertJsonPath('receiver.identity', 'u'.$orga->id.'-probe-rx')
        ->assertJsonPath('audio.bitrate', 24000);

    expect($this->fake->issued)->toBe([
        ['room' => 'lanomat-latency-u'.$orga->id, 'identity' => 'u'.$orga->id.'-probe-tx', 'name' => 'Olga (Latenztest)', 'canPublish' => true],
        ['room' => 'lanomat-latency-u'.$orga->id, 'identity' => 'u'.$orga->id.'-probe-rx', 'name' => 'Olga (Latenztest)', 'canPublish' => false],
    ]);
});

it('refuses the latency test token to helpers and participants', function (Role $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))->postJson('/talk/latency-test/token')->assertForbidden();

    expect($this->fake->issued)->toBe([]);
})->with([
    'helper' => [Role::Helper],
    'participant' => [Role::Participant],
]);

it('refuses the latency test token to guests', function () {
    $this->postJson('/talk/latency-test/token')->assertUnauthorized();

    expect($this->fake->issued)->toBe([]);
});

it('answers 503 when the voice server is not configured', function () {
    config(['webvoice.url' => '']);

    $this->actingAs(User::factory()->create(['role' => Role::Admin]))->postJson('/talk/latency-test/token')
        ->assertStatus(503)->assertJsonPath('message', 'Der Sprachserver ist gerade nicht erreichbar.');
});

it('never lists the test room as a channel', function () {
    $orga = User::factory()->create(['role' => Role::Orga]);
    WebVoiceChannel::factory()->create(['name' => 'Lobby']);

    $this->actingAs($orga)->postJson('/talk/latency-test/token')->assertOk();

    expect(WebVoiceChannel::query()->count())->toBe(1)
        ->and(app(VisibleChannels::class)->for($orga)->pluck('name')->all())->toBe(['Lobby']);
});

it('tells the page whether the viewer may measure', function (Role $role, bool $can) {
    $this->actingAs(User::factory()->create(['role' => $role]))->get('/talk')
        ->assertInertia(fn ($page) => $page->where('canMeasure', $can));
})->with([
    'admin' => [Role::Admin, true],
    'orga' => [Role::Orga, true],
    'helper' => [Role::Helper, false],
    'participant' => [Role::Participant, false],
]);
