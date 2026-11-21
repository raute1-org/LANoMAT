<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * The connect page is shared by every pairing target: the native LANoMAT
 * Launcher and the CLI agent both approve a code here. Its wording must
 * therefore talk about "Geräte", never about the agent alone.
 */
it('renders the pairing page with device-neutral German wording', function () {
    $user = User::factory()->create();

    $response = $this->withoutVite()->actingAs($user)->get('/mcp/connect');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Mcp/Connect')
        ->where('state', 'form')
        ->where('labels.title', 'Gerät verbinden')
        ->where('labels.device', 'Gerät')
        ->where('labels.devices_heading', 'Verbundene Geräte')
        ->where('labels.approved', 'Gerät verbunden. Du kannst zum Fenster zurückkehren.')
        ->where('labels.revoked', 'Gerät getrennt.')
        ->where('labels.confirm_heading', 'Dieses Gerät verbinden?')
        ->where('labels.devices_empty', 'Noch kein Gerät verbunden.')
        ->where('labels.devices_empty_hint', 'So erscheint dein erstes Gerät hier:')
        ->where('labels.devices_empty_command', 'lanomat-agent login'));

    $content = (string) $response->getContent();

    // Inertia escapes non-ASCII in the page payload, so the German strings
    // appear as \uXXXX there; the ASCII markers are literal.
    expect($content)
        ->toContain('Ger\u00e4t verbinden')
        ->toContain('Verbundene Ger\u00e4te')
        ->toContain('LANoMAT Launcher')
        ->toContain('lanomat-agent login')
        // The terminal-only wording of the first iteration is gone.
        ->not->toContain('Verbundene Agenten')
        ->not->toContain('Agent verbunden. Du kannst zum Terminal')
        ->not->toContain('Diesen Agenten verbinden?');
});

it('shows the pending device and its code on the neutral page', function () {
    $user = User::factory()->create();

    $pair = app(StartDeviceAuthorization::class)->handle('Wohnzimmer-PC');

    $response = $this->withoutVite()->actingAs($user)
        ->get('/mcp/connect?code='.urlencode($pair['user_code']));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Mcp/Connect')
        ->where('state', 'pending')
        ->where('code', $pair['user_code'])
        ->where('deviceName', 'Wohnzimmer-PC')
        ->where('labels.device', 'Gerät')
        ->where('labels.confirm_heading', 'Dieses Gerät verbinden?'));

    expect((string) $response->getContent())
        ->toContain($pair['user_code'])
        ->toContain('Wohnzimmer-PC');
});

it('lists connected devices under the neutral heading and offers both entry points when empty', function () {
    $user = User::factory()->create();

    $plain = McpCodes::bearerToken();

    (new McpToken)->forceFill([
        'user_id' => $user->getKey(),
        'name' => 'Wohnzimmer-PC',
        'token_hash' => McpCodes::hash($plain),
        'expires_at' => now()->addDays(90),
    ])->save();

    $response = $this->withoutVite()->actingAs($user)->get('/mcp/connect');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('labels.devices_heading', 'Verbundene Geräte')
        ->where('labels.devices_empty_launcher', 'LANoMAT Launcher: beim Start mit deinem Konto verbinden.')
        ->where('labels.devices_empty_agent', 'Agent in der Kommandozeile:')
        ->where('labels.devices_empty_command', 'lanomat-agent login')
        ->has('devices', 1)
        ->where('devices.0.name', 'Wohnzimmer-PC'));

    expect((string) $response->getContent())
        ->toContain('Wohnzimmer-PC')
        ->toContain('LANoMAT Launcher')
        ->toContain('lanomat-agent login')
        ->not->toContain($plain);
});
