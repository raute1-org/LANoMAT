<?php

use Illuminate\Support\Facades\Config;

function signedInteraction(array $body): array
{
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    Config::set('services.discord.public_key', bin2hex($public));

    $json = json_encode($body);
    $timestamp = (string) time();
    $sig = bin2hex(sodium_crypto_sign_detached($timestamp.$json, $secret));

    return [$json, $timestamp, $sig];
}

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
