<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/**
 * Builds the expiring download links the nginx file host verifies with
 * `ngx_http_secure_link_module` (`secure_link_md5`, the only variant in stock
 * nginx; the HMAC variant is a third-party module).
 *
 * Link: `{base}/dl/{path}?expires={unix}&md5={sig}&n={encoded file name}`
 *
 * nginx recomputes `base64url(md5("{expires}{uri}|{n} {secret}"))`, so the
 * signature covers the expiry, the exact path (`$uri`) and the download file
 * name (`$arg_n`, still percent-encoded as sent). Changing any of the three
 * invalidates the link. The secret is never part of a URL.
 *
 * Properties and limits are documented in docs/infra/file-host.md. Pure and
 * IO-free so a pinned test vector can prove parity with the documented
 * `openssl md5 -binary | base64 | tr +/ -_ | tr -d =` pipeline.
 */
final class FileUrlSigner
{
    public const PATH_PREFIX = '/dl/';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secret,
        private readonly int $ttlSeconds,
    ) {}

    public function url(string $path, string $downloadName, ?int $now = null): string
    {
        $expires = ($now ?? time()) + $this->ttlSeconds;
        $uri = self::PATH_PREFIX.ltrim($path, '/');
        // rawurlencode, never http_build_query: spaces must be %20 as nginx sees them.
        $encodedName = rawurlencode($downloadName);

        return rtrim($this->baseUrl, '/').$uri
            .'?expires='.$expires
            .'&md5='.$this->signature($expires, $uri, $encodedName)
            .'&n='.$encodedName;
    }

    public function signature(int $expires, string $uri, string $encodedName): string
    {
        $digest = md5($expires.$uri.'|'.$encodedName.' '.$this->secret, true);

        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    /**
     * A secret that is safe inside an nginx config value (no quoting, no `$`)
     * and long enough that guessing the MD5 input is hopeless.
     */
    public static function isAcceptableSecret(string $secret): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{32,}$/', $secret) === 1;
    }
}
