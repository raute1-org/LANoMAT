<?php

declare(strict_types=1);

namespace App\Modules\Identity\Connectors;

use App\Modules\Identity\Contracts\AvatarFetcher;
use App\Modules\Identity\Exceptions\AvatarFetchFailed;
use App\Modules\Identity\Support\AvatarProcessor;
use App\Modules\Identity\Support\FetchedAvatar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Fetches an avatar over the app's HTTP stack with hard limits: https only, the Discord CDN
 * hosts only (also for every redirect hop, which is followed by hand), 5 s timeouts, at most
 * 2 MB read, and an image content type. Whether the bytes really decode as an image is decided
 * by {@see AvatarProcessor}, not here.
 */
class HttpAvatarFetcher implements AvatarFetcher
{
    public const ALLOWED_HOSTS = ['cdn.discordapp.com', 'media.discordapp.net'];

    public const MAX_BYTES = 2 * 1024 * 1024;

    private const TIMEOUT_SECONDS = 5;

    private const MAX_REDIRECTS = 3;

    private const CONTENT_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public function fetch(string $url): FetchedAvatar
    {
        // Ask the CDN for a 256 px rendition (the stored size) instead of the full-size original.
        $current = str_contains($url, '?') ? $url : $url.'?size='.AvatarProcessor::SIZE;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            self::assertAllowed($current);

            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(self::TIMEOUT_SECONDS)
                    ->withOptions(['allow_redirects' => false, 'stream' => true])
                    ->accept('image/*')
                    ->get($current);
            } catch (ConnectionException) {
                throw AvatarFetchFailed::because('the CDN is unreachable');
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw AvatarFetchFailed::because('redirect without a target');
                }

                $current = self::resolve($current, $location);

                continue;
            }

            if ($status !== 200) {
                throw AvatarFetchFailed::because('the CDN answered '.$status);
            }

            $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

            if (! in_array($type, self::CONTENT_TYPES, true)) {
                throw AvatarFetchFailed::because('unexpected content type');
            }

            $length = $response->header('Content-Length');

            if ($length !== '' && (int) $length > self::MAX_BYTES) {
                throw AvatarFetchFailed::because('the image is larger than the limit');
            }

            return new FetchedAvatar($this->readBounded($response->toPsrResponse()->getBody()), $type);
        }

        throw AvatarFetchFailed::because('too many redirects');
    }

    private function readBounded(StreamInterface $body): string
    {
        $buffer = '';

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            if (strlen($buffer) > self::MAX_BYTES) {
                throw AvatarFetchFailed::because('the image is larger than the limit');
            }
        }

        if ($buffer === '') {
            throw AvatarFetchFailed::because('empty response');
        }

        return $buffer;
    }

    private static function assertAllowed(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_HOSTS, true)) {
            throw AvatarFetchFailed::because('the source is not an allowed https CDN host');
        }
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        return $scheme.'://'.($parts['host'] ?? '').'/'.ltrim($location, '/');
    }
}
