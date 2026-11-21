<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

/**
 * Whether recipe text may be published into the public git catalogue. The
 * recipe a participant shares was written on the LAN, so it may name the
 * orga's server, a personal e-mail address or a token pasted into a step.
 *
 * The rules mirror `scripts/ops/check-public-safe.sh` (same address classes,
 * same redaction: two leading characters plus the length, never the value).
 * Pure text in, findings out — no IO, so the proposal action can refuse a
 * recipe before a single request leaves LANoMAT.
 */
final class PublicSafeText
{
    /** Same pattern as the shell check; the lookarounds keep a version out of a longer number. */
    private const string IPV4 = '/(?<![\w.])(\d{1,3}(?:\.\d{1,3}){3})(?![\w]|\.\d)/';

    private const string EMAIL = '/(?<![\w.%+-])([A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})(?![A-Za-z0-9-])/';

    private const string PRIVATE_KEY = '/(-----BEGIN [A-Z ]*PRIVATE KEY-----)/';

    private const string SSH_PUBLIC_KEY = '/((?:ssh-(?:rsa|ed25519|dss)|ecdsa-sha2-nistp[0-9]+) AAAA[0-9A-Za-z+\/]{20,})/';

    /** Token prefixes LANoMAT and GitHub hand out; long enough to not catch prose. */
    private const string BEARER_TOKEN = '/(\b(?:lmcp_|lvc_|ghp_|github_pat_|gho_)[A-Za-z0-9_]{8,})/';

    /** Something that looks like an IPv6 literal: two or more groups of hex and colons. */
    private const string IPV6 = '/(?<![\w:])((?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4})(?![\w:])/';

    /** A URL whose authority is a dotted quad (`https://203.0.113.9:8080/...`). */
    private const string IPV4_URL_HOST = '~[a-z][a-z0-9+.-]*://(\d{1,3}(?:\.\d{1,3}){3})(?=[/:?#\s]|$)~i';

    /** E-mail domains a public recipe may name, next to the documentation `example.*` ones. */
    private const array EMAIL_DOMAINS = ['lanomat.local', 'users.noreply.github.com'];

    /**
     * Everything that keeps this text out of the public catalogue, in reading
     * order. Each finding names the class and a redacted value, so a blocked
     * recipe can be fixed without the reason itself leaking.
     *
     * @return list<string>
     */
    public function problems(string $text): array
    {
        $findings = [];

        foreach ($this->matches(self::PRIVATE_KEY, $text) as [$value, $offset]) {
            $findings[] = $this->finding('a private key block', $offset, $value, $text);
        }

        foreach ($this->matches(self::SSH_PUBLIC_KEY, $text) as [$value, $offset]) {
            $findings[] = $this->finding('an SSH public key', $offset, $value, $text);
        }

        foreach ($this->matches(self::BEARER_TOKEN, $text) as [$value, $offset]) {
            $findings[] = $this->finding('an access token', $offset, $value, $text);
        }

        foreach ($this->matches(self::EMAIL, $text) as [$value, $offset]) {
            if (! $this->emailAllowed($value)) {
                $findings[] = $this->finding('an e-mail address', $offset, $value, $text);
            }
        }

        foreach ($this->matches(self::IPV4, $text) as [$value, $offset]) {
            if ($this->versionOrFileName($value, $offset, $text)) {
                continue;
            }

            $class = $this->ipClass($value);

            if ($class === 'ok') {
                continue;
            }

            $label = $class === 'private' ? 'a private IPv4 address' : 'a public IPv4 address';

            $findings[] = $this->finding($label, $offset, $value, $text);
        }

        foreach ($this->matches(self::IPV4_URL_HOST, $text) as [$value, $offset]) {
            // A documentation-range address is fine in prose, but not as a URL host.
            if ($this->ipClass($value) !== 'ok') {
                continue;
            }

            $findings[] = $this->finding('a URL whose host is a bare IP address', $offset, $value, $text);
        }

        foreach ($this->matches(self::IPV6, $text) as [$value, $offset]) {
            if ($value === '::1' || ! $this->looksLikeIpv6($value)) {
                continue;
            }

            $findings[] = $this->finding('an IPv6 address', $offset, $value, $text);
        }

        return $findings;
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function matches(string $pattern, string $text): array
    {
        $found = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        if ($found === false || $found === 0) {
            return [];
        }

        $hits = [];

        foreach ($matches[1] as [$value, $offset]) {
            $hits[] = [$value, (int) $offset];
        }

        return $hits;
    }

    /** `line 3: a private IPv4 address 10[REDACTED len=10].` — the value never appears in full. */
    private function finding(string $label, int $offset, string $value, string $text): string
    {
        $line = substr_count(substr($text, 0, $offset), "\n") + 1;

        return sprintf('line %d: %s %s', $line, $label, $this->redact($value)).'.';
    }

    /** The shell rule: two leading characters plus the length, never the value. */
    private function redact(string $value): string
    {
        return mb_substr($value, 0, 2).'[REDACTED len='.mb_strlen($value).']';
    }

    private function emailAllowed(string $email): bool
    {
        $domain = mb_strtolower(substr($email, (int) strrpos($email, '@') + 1));

        if (in_array($domain, self::EMAIL_DOMAINS, true)) {
            return true;
        }

        return preg_match('/(?:^|\.)example\.[a-z]+$/', $domain) === 1;
    }

    /** The address classes `check-public-safe.sh` accepts (documentation, loopback, special-use). */
    private function ipClass(string $ip): string
    {
        $octets = array_map(intval(...), explode('.', $ip));

        if (count($octets) !== 4) {
            return 'public';
        }

        [$a, $b, $c] = $octets;

        if ($a === 10
            || ($a === 172 && $b >= 16 && $b <= 31)
            || ($a === 192 && $b === 168)
            || ($a === 100 && $b >= 64 && $b <= 127)) {
            return 'private';
        }

        if (in_array($ip, ['1.1.1.1', '8.8.8.8', '9.9.9.9', '0.0.0.0', '255.255.255.255'], true)) {
            return 'ok';
        }

        if ($a === 127 || $a === 0 || $a === 255
            || ($a === 169 && $b === 254)
            || ($a === 203 && $b === 0 && $c === 113)
            || ($a === 198 && $b === 51 && $c === 100)
            || ($a === 192 && $b === 0 && $c === 2)) {
            return 'ok';
        }

        return 'public';
    }

    /** Whether the dotted quad is a version label or part of a file name rather than an address. */
    private function versionOrFileName(string $value, int $offset, string $text): bool
    {
        $lineStart = strrpos(substr($text, 0, $offset), "\n");
        $prefix = substr($text, $lineStart === false ? 0 : $lineStart + 1, $offset - ($lineStart === false ? 0 : $lineStart + 1));

        // `version: 1.2.3.4`, `"version": "1.2.3.4"` in a YAML or JSON document.
        if (preg_match('/["\']?version["\']?\s*[:=]\s*["\']?[\s]*$/i', $prefix) === 1) {
            return true;
        }

        $after = substr($text, $offset + mb_strlen($value));

        // `tiny-racer-1.2.3.4.zip`: a version inside a file name, marked by the
        // file-name character before it and the extension after it.
        if (preg_match('/^\.[A-Za-z]/', $after) === 1 && $prefix !== '' && preg_match('/[A-Za-z0-9_-]$/', $prefix) === 1) {
            return true;
        }

        return false;
    }

    /** Whether a colon run is an IPv6 literal and not a clock time, a MAC address or a Windows path. */
    private function looksLikeIpv6(string $value): bool
    {
        $colons = substr_count($value, ':');

        if ($colons < 2) {
            return false;
        }

        // `aa:bb:cc:dd:ee:ff` is six two-digit groups, a MAC address, not an address of a host.
        if ($colons === 5 && preg_match('/^[0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5}$/', $value) === 1) {
            return false;
        }

        // `12:30:45` has the shape but neither a `::` compression nor a hex letter.
        return str_contains($value, '::')
            || preg_match('/[A-Fa-f]/', $value) === 1
            || ($colons === 7 && preg_match('/^(?:[0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}$/', $value) === 1);
    }
}
