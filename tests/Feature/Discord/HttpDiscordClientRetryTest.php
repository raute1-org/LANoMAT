<?php

use App\Modules\Discord\HttpDiscordClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('retries once after a 429 with Retry-After and then succeeds', function () {
    Http::fake([
        'discord.com/*' => Http::sequence()
            ->push(['message' => 'You are being rate limited.'], 429, ['Retry-After' => '0'])
            ->push(['id' => '99'], 200),
    ]);

    (new HttpDiscordClient('test-token'))->sendMessage('42', 'hi');

    Http::assertSentCount(2);
});

it('throws after exhausting retries against a persistent 429', function () {
    Http::fake([
        'discord.com/*' => Http::response(['message' => 'You are being rate limited.'], 429, ['Retry-After' => '0']),
    ]);

    expect(fn () => (new HttpDiscordClient('test-token'))->sendMessage('42', 'hi'))
        ->toThrow(RequestException::class);

    // retry(3, ...) means 3 total attempts (not 3 retries after the first).
    Http::assertSentCount(3);
});
