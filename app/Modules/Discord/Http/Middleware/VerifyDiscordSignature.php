<?php

namespace App\Modules\Discord\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyDiscordSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Signature-Ed25519');
        $timestamp = $request->header('X-Signature-Timestamp');
        $publicKey = config('services.discord.public_key');

        if (blank($signature) || blank($timestamp) || blank($publicKey)) {
            abort(401);
        }

        // The raw, unparsed body is required here: Discord signs the exact bytes
        // it sent, so verification must happen before any JSON decoding touches
        // the request. $request->getContent() returns those raw bytes untouched.
        $rawBody = $request->getContent();

        if (! $this->isValidSignature($signature, $timestamp, $rawBody, $publicKey)) {
            abort(401);
        }

        return $next($request);
    }

    private function isValidSignature(string $signature, string $timestamp, string $rawBody, string $publicKey): bool
    {
        $decodedSignature = hex2bin($signature);
        $decodedPublicKey = hex2bin($publicKey);

        if ($decodedSignature === false || $decodedPublicKey === false) {
            return false;
        }

        if (strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($decodedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($decodedSignature, $timestamp.$rawBody, $decodedPublicKey);
        } catch (\SodiumException) {
            return false;
        }
    }
}
