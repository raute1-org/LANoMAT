<?php

use Illuminate\Support\Facades\Config;

it('rejects a request missing the signature headers', function () {
    Config::set('services.discord.public_key', bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));

    $this->call('POST', '/api/discord/interactions', [], [], [],
        ['CONTENT_TYPE' => 'application/json'], json_encode(['type' => 1]))
        ->assertStatus(401);
});

it('rejects a tampered body even with a validly formatted signature', function () {
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    Config::set('services.discord.public_key', bin2hex($public));

    $timestamp = (string) time();
    $originalJson = json_encode(['type' => 1]);
    $sig = bin2hex(sodium_crypto_sign_detached($timestamp.$originalJson, $secret));

    // Body sent differs from the body that was signed.
    $tamperedJson = json_encode(['type' => 2]);

    $this->call('POST', '/api/discord/interactions', [], [], [],
        ['HTTP_X-Signature-Ed25519' => $sig, 'HTTP_X-Signature-Timestamp' => $timestamp,
            'CONTENT_TYPE' => 'application/json'], $tamperedJson)
        ->assertStatus(401);
});

it('dispatches an unknown application command to an error response', function () {
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    Config::set('services.discord.public_key', bin2hex($public));

    $body = [
        'type' => 2,
        'data' => ['name' => 'does-not-exist'],
    ];
    $json = json_encode($body);
    $timestamp = (string) time();
    $sig = bin2hex(sodium_crypto_sign_detached($timestamp.$json, $secret));

    $this->call('POST', '/api/discord/interactions', [], [], [],
        ['HTTP_X-Signature-Ed25519' => $sig, 'HTTP_X-Signature-Timestamp' => $timestamp,
            'CONTENT_TYPE' => 'application/json'], $json)
        ->assertOk()
        ->assertJsonPath('data.content', fn ($content) => is_string($content));
});
