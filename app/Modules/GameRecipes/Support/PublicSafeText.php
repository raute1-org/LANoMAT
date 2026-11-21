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
 *
 * A dotted quad counts as an address unless the surrounding words say it is a
 * version ("version 1.2.3.4", "GTA 5.1.2.3 patch", "Euro Truck 1.53.2.5.zip");
 * an address in prose, after a host word, a URL scheme or an `@`, with a port,
 * or as a decimal/hex URL host always counts. When the context is ambiguous the
 * gate refuses: a blocked recipe is fixable, a leaked address is not. It stops
 * accidents — a pasted IP, an e-mail, a token — not someone determined to
 * disguise one (an address split into separate YAML keys, an image, base64).
 *
 * {@see markdown()} turns participant-authored text inert for the pull request
 * title and body, where an image would phone home and a mention would ping.
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

    /** The same host as one decimal (`http://2130706433/`) or one hex integer (`http://0xC0A80101/`). */
    private const string IPV4_URL_HOST_NUMERIC = '~[a-z][a-z0-9+.-]*://(\d{5,10}|0x[0-9A-Fa-f]{5,8})(?=[/:?#\s]|$)~i';

    /** A dotted quad split by a space after a dot or by a YAML line fold (`192.168. 1.5`). */
    private const string IPV4_SPLIT = '/(?<![\w.])((?:\d{1,3}\.\d{1,3})\.(?:\s*\n\s*|\s+)\d{1,3}\.\d{1,3})(?![\w]|\.\d)/';

    /** Cloud and chat credentials: AWS, Slack and OpenAI keys. */
    private const string CLOUD_TOKEN = '/(\bAKIA[0-9A-Z]{16}\b|\bxox[baprs]-[A-Za-z0-9-]{10,}|\bsk-[A-Za-z0-9_-]{20,})/';

    /** A Discord bot token: base64 id, timestamp part, HMAC part. */
    private const string DISCORD_TOKEN = '/(\b[MNO][A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{6}\.[A-Za-z0-9_-]{27,40}\b)/';

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

        foreach ($this->matches(self::CLOUD_TOKEN, $text) as [$value, $offset]) {
            $findings[] = $this->finding('an access token', $offset, $value, $text);
        }

        foreach ($this->matches(self::DISCORD_TOKEN, $text) as [$value, $offset]) {
            $findings[] = $this->finding('an access token', $offset, $value, $text);
        }

        foreach ($this->matches(self::EMAIL, $text) as [$value, $offset]) {
            if (! $this->emailAllowed($value)) {
                $findings[] = $this->finding('an e-mail address', $offset, $value, $text);
            }
        }

        foreach ($this->matches(self::IPV4_SPLIT, $text) as [$value, $offset]) {
            $findings[] = $this->finding('a dotted IPv4 address', $offset, $value, $text);
        }

        foreach ($this->matches(self::IPV4, $text) as [$value, $offset]) {
            $addressContext = $this->addressContext($value, $offset, $text);

            if (! $addressContext && $this->versionOrFileName($value, $offset, $text)) {
                continue;
            }

            $class = $this->ipClass($value);

            if ($class === 'ok' && ! $addressContext) {
                continue;
            }

            $label = match ($class) {
                'private' => 'a private IPv4 address',
                'link-local' => 'a link-local IPv4 address',
                default => 'a public IPv4 address',
            };

            $findings[] = $this->finding($label, $offset, $value, $text);
        }

        foreach ($this->matches(self::IPV4_URL_HOST, $text) as [$value, $offset]) {
            // A documentation-range address is fine in prose, but not as a URL host.
            if ($this->ipClass($value) !== 'ok') {
                continue;
            }

            $findings[] = $this->finding('a URL whose host is a bare IP address', $offset, $value, $text);
        }

        foreach ($this->matches(self::IPV4_URL_HOST_NUMERIC, $text) as [$value, $offset]) {
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

        if ($a === 169 && $b === 254) {
            return 'link-local';
        }

        if ($a === 127 || $a === 0 || $a === 255
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
        $prefix = $this->prefix($offset, $text);
        $after = substr($text, $offset + mb_strlen($value));

        // `version: 1.2.3.4`, `"version": "1.2.3.4"` in a YAML or JSON document.
        if (preg_match('/["\']?version["\']?\s*[:=]\s*["\']?\s*$/i', $prefix) === 1) {
            return true;
        }

        // `tiny-racer-1.2.3.4.zip`, `Euro Truck 1.53.2.5.zip`: a file name ends here.
        if (preg_match('/^\.[A-Za-z]/', $after) === 1) {
            return true;
        }

        // `version 1.2.3.4`, `Patch 1.0.0.1`, `build v1.2.3.4`.
        if (preg_match('/\b(?:version|ver|patch|build|rev|revision|release|update|beta|alpha|rc|hotfix|v)\.?\s*$/i', $prefix) === 1) {
            return true;
        }

        // `GTA 5.1.2.3 patch`, `5.1.2.3 build`.
        return preg_match('/^\s*(?:patch|build|update|version|release|beta|alpha|rc|hotfix)\b/i', $after) === 1;
    }

    /** Whether the text around the quad says it is a host value, which beats a version label. */
    private function addressContext(string $value, int $offset, string $text): bool
    {
        $prefix = $this->prefix($offset, $text);
        $after = substr($text, $offset + mb_strlen($value));

        // A URL authority or a user part: `http://…`, `games@…`.
        if (preg_match('#(?:://|@)$#', $prefix) === 1) {
            return true;
        }

        // A port: `192.168.1.5:27015`.
        if (preg_match('/^:\d{1,5}(?!\d)/', $after) === 1) {
            return true;
        }

        // A host-ish label right before: `server 10.1.2.3`, `address: 172.16.5.9`, `IP=…`.
        return preg_match('/\b(?:host|hostname|ip|ipv4|address|addr|server|connect|endpoint)["\']?\s*[:=]?\s*$/i', $prefix) === 1;
    }

    /** The text from the start of the line up to the match. */
    private function prefix(int $offset, string $text): string
    {
        $lineStart = strrpos(substr($text, 0, $offset), "\n");

        return substr($text, $lineStart === false ? 0 : $lineStart + 1, $offset - ($lineStart === false ? 0 : $lineStart + 1));
    }

    /**
     * Participant text for a place that is not Markdown: a pull request title
     * and a commit message. Backslash escapes would show up literally there,
     * so the characters that still do something are removed instead: `@`
     * (mentions), `#` (issue references, and with them "Fixes #12"), the
     * backtick and angle brackets. One line, capped.
     */
    public function plain(string $text, int $limit = 120): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;
        $text = str_replace(['@', '#', '`', '<', '>', '\\'], ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) <= $limit ? $text : rtrim(mb_substr($text, 0, $limit));
    }

    /**
     * Participant-authored text as inert Markdown: one line, no control
     * characters, every Markdown/HTML metacharacter escaped (so an image link
     * cannot phone home, `@team` cannot ping and `Fixes #12` cannot close an
     * issue), capped at `$limit` characters. Code spans would hide the text
     * from a reader; escaping keeps it readable.
     */
    public function markdown(string $text, int $limit = 200): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        $text = preg_replace('/([\\\\`*_{}\[\]()#+.!|<>@~-])/', '\\\\$1', $text) ?? $text;

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit), "\\ \t");
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
