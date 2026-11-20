<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function mcpCommandToken(string $output): string
{
    if (preg_match('/(lmcp_[A-Za-z0-9]{48})/', $output, $matches) !== 1) {
        throw new RuntimeException('The command did not print an MCP token: '.$output);
    }

    return $matches[1];
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('issues a token by user id, prints it once and stores only the digest', function () {
    Carbon::setTestNow('2026-11-21 18:00:00');
    $user = User::factory()->create();

    expect(Artisan::call('lanomat:mcp-token', ['user' => (string) $user->id]))->toBe(0);

    $plain = mcpCommandToken(Artisan::output());
    $token = McpToken::query()->sole();

    expect($token->user_id)->toBe($user->id)
        ->and($token->name)->toBe('CLI')
        ->and($token->token_hash)->toBe(McpCodes::hash($plain))
        ->and($token->token_hash)->not->toBe($plain)
        ->and($token->expires_at?->getTimestamp())->toBe(now()->addDays(90)->getTimestamp())
        ->and($token->last_used_at)->toBeNull();

    $raw = DB::table('mcp_tokens')->first();
    $flat = implode('|', array_map(static fn ($value): string => (string) $value, (array) $raw));

    expect($raw->token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($flat)->not->toContain($plain);

    $this->withToken($plain)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

it('looks a user up by email (any case) and by Discord id', function () {
    $user = User::factory()->create([
        'email' => 'orga@lan.example',
        'discord_id' => '4242424242',
    ]);

    $this->artisan('lanomat:mcp-token', ['user' => 'Orga@LAN.example'])->assertSuccessful();
    $this->artisan('lanomat:mcp-token', ['user' => '4242424242'])->assertSuccessful();

    $rows = McpToken::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (McpToken $token): bool => $token->user_id === $user->id))->toBeTrue();
});

it('matches a mixed-case stored email case-insensitively', function () {
    $user = User::factory()->create(['email' => 'Mixed@Case.Example']);

    expect(Artisan::call('lanomat:mcp-token', ['user' => 'mixed@case.example']))->toBe(0);

    expect(McpToken::query()->sole()->user_id)->toBe($user->id);
});

it('resolves id, email and Discord collisions deterministically by id', function () {
    $byEmail = User::factory()->create(['email' => '4242']);
    $byDiscord = User::factory()->create(['discord_id' => '4242']);

    expect($byEmail->id)->toBeLessThan($byDiscord->id);

    expect(Artisan::call('lanomat:mcp-token', ['user' => '4242']))->toBe(0);

    expect(McpToken::query()->sole()->user_id)->toBe($byEmail->id);
});

it('fails cleanly for an unknown user and creates nothing', function () {
    $this->artisan('lanomat:mcp-token', ['user' => 'nobody@lan.example'])
        ->expectsOutputToContain('No user matches that id, email or Discord id.')
        ->assertFailed();

    $this->artisan('lanomat:mcp-token', ['user' => '999999'])
        ->expectsOutputToContain('No user matches')
        ->assertFailed();

    expect(McpToken::query()->count())->toBe(0);
});

it('stores the --name, strips control characters and caps it at the column width', function () {
    $user = User::factory()->create();

    $this->artisan('lanomat:mcp-token', ['user' => (string) $user->id, '--name' => "  Kiosk\x01  "])
        ->assertSuccessful();
    expect(McpToken::query()->orderByDesc('id')->first()?->name)->toBe('Kiosk');

    $this->artisan('lanomat:mcp-token', ['user' => (string) $user->id, '--name' => str_repeat('N', 120)])
        ->assertSuccessful();
    expect(McpToken::query()->orderByDesc('id')->first()?->name)->toHaveLength(60);

    $this->artisan('lanomat:mcp-token', ['user' => (string) $user->id, '--name' => "\x01\x02"])
        ->assertSuccessful();
    expect(McpToken::query()->orderByDesc('id')->first()?->name)->toBe('CLI');

    $this->artisan('lanomat:mcp-token', ['user' => (string) $user->id, '--name' => ''])
        ->assertSuccessful();
    expect(McpToken::query()->orderByDesc('id')->first()?->name)->toBe('CLI');
});

it('mints a different token on every run', function () {
    $user = User::factory()->create();

    expect(Artisan::call('lanomat:mcp-token', ['user' => (string) $user->id]))->toBe(0);
    $first = mcpCommandToken(Artisan::output());

    expect(Artisan::call('lanomat:mcp-token', ['user' => (string) $user->id]))->toBe(0);
    $second = mcpCommandToken(Artisan::output());

    expect($first)->not->toBe($second)
        ->and(McpToken::query()->count())->toBe(2)
        ->and(McpToken::query()->pluck('token_hash')->all())
        ->toBe([McpCodes::hash($first), McpCodes::hash($second)]);
});
