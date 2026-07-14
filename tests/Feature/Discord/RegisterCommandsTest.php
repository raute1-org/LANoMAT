<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.discord.application_id' => 'app-123',
        'services.discord.bot_token' => 'bot-token-abc',
    ]);
});

it('bulk-overwrites the global slash commands via a single PUT request', function () {
    Http::fake([
        'discord.com/api/v10/applications/app-123/commands' => Http::response([], 200),
    ]);

    $this->artisan('discord:register-commands')->assertSuccessful();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://discord.com/api/v10/applications/app-123/commands'
            && $request->method() === 'PUT'
            && $request->hasHeader('Authorization', 'Bot bot-token-abc')
            && is_array($request->data());
    });
    Http::assertSentCount(1);
});

it('fails fast without calling Discord when application id or bot token is missing', function () {
    config(['services.discord.application_id' => null]);
    Http::fake();

    $this->artisan('discord:register-commands')->assertFailed();

    Http::assertNothingSent();
});
