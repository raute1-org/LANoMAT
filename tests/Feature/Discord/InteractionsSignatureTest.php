<?php

// signedInteraction() is a shared test helper declared in tests/Pest.php.

it('rejects an invalid signature', function () {
    [$json, $timestamp] = signedInteraction(['type' => 1]);

    $this->call('POST', '/api/discord/interactions', [], [], [],
        ['HTTP_X-Signature-Ed25519' => str_repeat('0', 128), 'HTTP_X-Signature-Timestamp' => $timestamp,
            'CONTENT_TYPE' => 'application/json'], $json)
        ->assertStatus(401);
});

it('answers PING with PONG for a valid signature', function () {
    [$json, $timestamp, $sig] = signedInteraction(['type' => 1]);

    $this->call('POST', '/api/discord/interactions', [], [], [],
        ['HTTP_X-Signature-Ed25519' => $sig, 'HTTP_X-Signature-Timestamp' => $timestamp,
            'CONTENT_TYPE' => 'application/json'], $json)
        ->assertOk()
        ->assertJson(['type' => 1]);
});
