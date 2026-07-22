<?php

use App\Modules\Discord\Jobs\SendFollowupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.discord.gateway_bridge_secret' => 'test-secret',
        'services.discord.application_id' => 'app-123',
    ]);
});

$helpPayload = [
    'type' => 2,
    'application_id' => 'app-123',
    'token' => 'tok',
    'member' => ['user' => ['id' => '900']],
    'data' => ['name' => 'help', 'options' => []],
];

it('routes a help command through the gateway ingress', function () use ($helpPayload) {
    Bus::fake();
    $this->postJson('/internal/discord/gateway', ['type' => 'interaction', 'data' => $helpPayload], ['X-Gateway-Secret' => 'test-secret'])
        ->assertNoContent();
    Bus::assertDispatched(SendFollowupJob::class);
});

it('still serves the HTTP fallback route (dormant, not deleted)', function () use ($helpPayload) {
    // The route exists and is wired to CommandRouter; the Ed25519 middleware
    // rejects an unsigned request (401), proving the guarded fallback is live.
    $this->postJson('/api/discord/interactions', $helpPayload)->assertUnauthorized();
    expect(app('router')->getRoutes()->hasNamedRoute('discord.interactions'))->toBeTrue();
});
