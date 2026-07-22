<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['services.discord.gateway_bridge_secret' => 'test-secret']));

it('401s without the gateway secret', function () {
    $this->postJson('/internal/discord/gateway', ['type' => 'noop', 'data' => []])
        ->assertUnauthorized();
});

it('401s with a wrong gateway secret', function () {
    $this->postJson('/internal/discord/gateway', ['type' => 'noop', 'data' => []], ['X-Gateway-Secret' => 'nope'])
        ->assertUnauthorized();
});

it('accepts a correct gateway secret and ignores unknown event types', function () {
    $this->postJson('/internal/discord/gateway', ['type' => 'noop', 'data' => []], ['X-Gateway-Secret' => 'test-secret'])
        ->assertNoContent();
});
