<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Identity\Contracts\GuildMembershipChecker;
use App\Modules\Identity\Testing\FakeGuildMembershipChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

const LOGIN_GUILD_ID = '987654321000000001';
const NOT_A_MEMBER_MESSAGE = 'Zugang nur für Mitglieder des LAN-Discord-Servers. Tritt dem Server bei und versuche es erneut.';
const CHECK_UNAVAILABLE_MESSAGE = 'Die Mitgliedschaft im LAN-Discord-Server konnte gerade nicht geprüft werden. Bitte versuche es in einer Minute erneut.';

function guildGateDiscordUser(string $id = '123456789', string $name = 'TestUser', ?string $avatar = 'https://cdn.discordapp.com/avatars/123/abc.png'): SocialiteUser
{
    $user = new SocialiteUser;
    $user->map([
        'id' => $id,
        'nickname' => $name,
        'name' => $name,
        'email' => 'test@example.com',
        'avatar' => $avatar,
    ]);
    $user->setToken('discord-access-token');

    return $user;
}

function guildChecker(): FakeGuildMembershipChecker
{
    return app(FakeGuildMembershipChecker::class);
}

beforeEach(function () {
    config(['services.discord.login_guild_id' => LOGIN_GUILD_ID]);
    app()->instance(FakeGuildMembershipChecker::class, new FakeGuildMembershipChecker);
    app()->instance(GuildMembershipChecker::class, guildChecker());
});

it('lets a guild member log in', function () {
    guildChecker()->willBeMember(true);
    Socialite::shouldReceive('driver->user')->andReturn(guildGateDiscordUser());

    $this->get('/auth/discord/callback')->assertRedirect('/');

    $user = User::where('discord_id', '123456789')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect(guildChecker()->checked)->toBe([LOGIN_GUILD_ID])
        ->and(guildChecker()->tokens)->toBe(['discord-access-token']);
});

it('denies a non-member without creating a user or a session', function () {
    guildChecker()->willBeMember(false);
    Socialite::shouldReceive('driver->user')->andReturn(guildGateDiscordUser());

    $response = $this->get('/auth/discord/callback');

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', NOT_A_MEMBER_MESSAGE);
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('fails closed when the membership check is unavailable', function () {
    guildChecker()->willFail();
    Socialite::shouldReceive('driver->user')->andReturn(guildGateDiscordUser());

    $response = $this->get('/auth/discord/callback');

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', CHECK_UNAVAILABLE_MESSAGE);
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('lets everyone log in when no login guild is configured', function () {
    config(['services.discord.login_guild_id' => null]);
    Socialite::shouldReceive('driver->user')->andReturn(guildGateDiscordUser());

    $this->get('/auth/discord/callback')->assertRedirect('/');

    $this->assertAuthenticated();
    expect(guildChecker()->checked)->toBe([]);
});

it('lets an existing admin in even when they are not a guild member', function () {
    guildChecker()->willBeMember(false);
    User::factory()->admin()->create(['discord_id' => '123456789']);
    Socialite::shouldReceive('driver->user')->andReturn(guildGateDiscordUser());

    $this->get('/auth/discord/callback')->assertRedirect('/');

    $this->assertAuthenticated();
    expect(guildChecker()->checked)->toBe([]);
});

it('does not exempt a non-admin existing user from the membership check', function () {
    guildChecker()->willBeMember(false);
    $existing = User::factory()->create([
        'discord_id' => '123456789',
        'role' => Role::Participant,
        'avatar_url' => 'https://cdn.discordapp.com/avatars/123/old.png',
    ]);
    Socialite::shouldReceive('driver->user')->andReturn(guildGateDiscordUser());

    $response = $this->get('/auth/discord/callback');

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', NOT_A_MEMBER_MESSAGE);
    $this->assertGuest();
    expect(User::count())->toBe(1)
        ->and($existing->refresh()->avatar_url)->toContain('old.png');
});

it('requests the guilds.members.read scope only when a login guild is configured', function () {
    config(['services.discord.client_id' => 'test-client-id']);

    $withGate = $this->get('/auth/discord/redirect');
    parse_str((string) parse_url((string) $withGate->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['scope'])->toBe('identify email guilds.members.read');

    // Socialite caches the driver per application instance and scopes() only
    // adds. Production gets a fresh app per request, so mirror that here.
    $this->refreshApplication();
    config(['services.discord.client_id' => 'test-client-id', 'services.discord.login_guild_id' => null]);
    $withoutGate = $this->get('/auth/discord/redirect');
    parse_str((string) parse_url((string) $withoutGate->headers->get('Location'), PHP_URL_QUERY), $plainQuery);
    expect($plainQuery['scope'])->toBe('identify email');
});
