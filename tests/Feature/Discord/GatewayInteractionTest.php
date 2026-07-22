<?php

use App\Modules\Discord\Jobs\SendFollowupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.discord.gateway_bridge_secret' => 'test-secret',
        'services.discord.application_id' => 'app-123',
    ]);
});

// Named distinctly from SlashCommandTest.php's postInteraction() helper
// (Pest declares top-level test functions in a shared global namespace, so
// two files can't both define a function with the same name) — that helper
// posts a signed payload to the Ed25519-verified api/discord/interactions
// endpoint, this one posts an unsigned envelope to the gateway ingress.
function postGatewayInteraction(array $interaction): TestResponse
{
    return test()->postJson(
        '/internal/discord/gateway',
        ['type' => 'interaction', 'data' => $interaction],
        ['X-Gateway-Secret' => 'test-secret'],
    );
}

it('delivers an immediate command response as a follow-up job', function () {
    Bus::fake();

    postGatewayInteraction([
        'type' => 2,
        'application_id' => 'app-123',
        'token' => 'interaction-token',
        'member' => ['user' => ['id' => '900']],
        'data' => ['name' => 'help', 'options' => []],
    ])->assertNoContent();

    Bus::assertDispatched(
        SendFollowupJob::class,
        fn (SendFollowupJob $job) => $job->applicationId === 'app-123'
            && $job->token === 'interaction-token'
            && $job->content !== '',
    );
});

it('does not double-dispatch for a deferred (type 5) command', function () {
    Bus::fake();

    // /tournament bracket is a deferred handler that queues its own follow-up.
    postGatewayInteraction([
        'type' => 2,
        'application_id' => 'app-123',
        'token' => 'interaction-token',
        'member' => ['user' => ['id' => '900']],
        'data' => ['name' => 'tournament', 'options' => [
            ['name' => 'bracket', 'type' => 1, 'options' => [
                ['name' => 'id', 'type' => 3, 'value' => '999999'],
            ]],
        ]],
    ])->assertNoContent();

    // Exactly one follow-up (the handler's own), not a second from the ingress.
    Bus::assertDispatchedTimes(SendFollowupJob::class, 1);
});
