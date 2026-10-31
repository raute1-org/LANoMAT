<?php

namespace App\Support\Security;

/**
 * Builds the Content-Security-Policy for the two page families of the app:
 *
 *  - `app`: the Inertia/Vue participant, beamer and overlay pages. No Alpine,
 *    so scripts are limited to 'self' + the per-request nonce (Laravel's
 *    `Vite::useCspNonce()` stamps it on every @vite tag; the inline scripts in
 *    `app.blade.php` carry it explicitly). No 'unsafe-eval'.
 *  - `panel`: the Filament admin. Alpine compiles x-data/x-on expressions with
 *    `new Function`, so Filament's own docs state that `script-src` still needs
 *    'unsafe-eval' there (Livewire's CSP-safe build is not an option for
 *    Filament); the nonce still covers its script tags.
 *
 * `'wasm-unsafe-eval'` allows WebAssembly compilation only (the self-hosted
 * ZXing decoder of the check-in camera scanner), not string evaluation.
 *
 * `style-src` keeps 'unsafe-inline': Filament documents that it does not
 * support a strict style-src (Livewire, Alpine and TipTap use inline styles)
 * and the Vue UI binds `style` attributes.
 */
final class ContentSecurityPolicy
{
    public const PROFILE_APP = 'app';

    public const PROFILE_PANEL = 'panel';

    public const REPORT_GROUP = 'csp-endpoint';

    /**
     * @param  list<string>  $formActionHosts
     */
    public function __construct(
        private readonly string $nonce,
        private readonly string $profile = self::PROFILE_APP,
        private readonly ?string $reverbConnectSource = null,
        private readonly array $formActionHosts = [],
        private readonly string $reportPath = '/csp-report',
        private readonly ?string $viteDevOrigin = null,
        private readonly ?string $webVoiceConnectSource = null,
        private readonly ?string $fileHostConnectSource = null,
    ) {}

    public function header(): string
    {
        $script = ["'self'", "'nonce-{$this->nonce}'", "'wasm-unsafe-eval'"];

        if ($this->profile === self::PROFILE_PANEL) {
            $script[] = "'unsafe-eval'";
        }

        $style = ["'self'", "'unsafe-inline'"];
        $connect = ["'self'"];

        if ($this->reverbConnectSource !== null) {
            $connect[] = $this->reverbConnectSource;
        }

        if ($this->webVoiceConnectSource !== null) {
            $connect[] = $this->webVoiceConnectSource;
        }

        if ($this->fileHostConnectSource !== null) {
            $connect[] = $this->fileHostConnectSource;
        }

        if ($this->viteDevOrigin !== null) {
            $script[] = $this->viteDevOrigin;
            $style[] = $this->viteDevOrigin;
            $connect[] = $this->viteDevOrigin;
            $connect[] = preg_replace('#^http#', 'ws', $this->viteDevOrigin) ?? $this->viteDevOrigin;
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            // Avatars come from the Discord CDN (https), uploads/QR from 'self'/data:/blob:.
            "img-src 'self' data: blob: https:",
            "font-src 'self'",
            'connect-src '.implode(' ', $connect),
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            'form-action '.implode(' ', ["'self'", ...$this->formActionHosts]),
            "report-uri {$this->reportPath}",
            'report-to '.self::REPORT_GROUP,
        ];

        return implode('; ', $directives);
    }

    public function reportingEndpoints(): string
    {
        return self::REPORT_GROUP.'="'.$this->reportPath.'"';
    }

    /**
     * Browser-facing signaling origin of the web voice SFU (`wss://voice.example.org`), for connect-src.
     * Only a wss:// or ws:// URL yields a source; WebRTC media itself (UDP) is not governed by CSP.
     */
    public static function webVoiceSource(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['wss', 'ws'], true)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Origin of the file host's tusd endpoint (`https://files.example.org:8443`) for connect-src: the
     * browser sends resumable uploads there directly. Only an http(s) URL yields a source.
     */
    public static function fileHostSource(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** Browser-facing WebSocket origin of the Reverb server, e.g. `wss://lan.example.org:443`. */
    public static function reverbSource(mixed $host, mixed $port, mixed $scheme): ?string
    {
        if (! is_string($host) || $host === '') {
            return null;
        }

        $socketScheme = $scheme === 'https' ? 'wss' : 'ws';
        $suffix = is_scalar($port) && (string) $port !== '' ? ':'.$port : '';

        return "{$socketScheme}://{$host}{$suffix}";
    }
}
