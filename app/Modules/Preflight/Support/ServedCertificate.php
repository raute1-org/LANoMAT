<?php

namespace App\Modules\Preflight\Support;

use Carbon\CarbonImmutable;

/**
 * Reads when the certificate a TLS server actually presents expires. It does not verify the
 * certificate (that is the HTTP probe's job); an expired one must still be readable here so the
 * check can say how long ago it ran out. A seam of its own so tests need no real TLS server.
 */
class ServedCertificate
{
    public function expiresAt(string $host, int $port): ?CarbonImmutable
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $stream = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            3,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            return null;
        }

        $params = stream_context_get_params($stream);
        fclose($stream);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $certificate === null ? false : openssl_x509_parse($certificate);

        return is_array($parsed) && isset($parsed['validTo_time_t'])
            ? CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t'])
            : null;
    }
}
