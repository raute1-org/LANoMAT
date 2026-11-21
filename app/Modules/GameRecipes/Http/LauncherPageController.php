<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Http;

use App\Http\Controllers\Controller;
use App\Modules\GameRecipes\Support\LauncherBuilds;
use App\Modules\GameServers\Support\JoinLinkTemplates;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The `/launcher` page and the file route behind it: one download per OS from
 * the validated launcher manifest, with checksums, install steps and the address of
 * this server to pair with.
 *
 * Both routes require login, and the page carries no user data. The set of files is whatever
 * {@see LauncherBuilds} lists; a name from the request is only ever compared with that
 * list, never used as a path.
 */
class LauncherPageController extends Controller
{
    public function index(
        Request $request,
        LauncherBuilds $builds,
        Modules $modules,
    ): Response {
        $manifest = $builds->manifest();
        $files = $builds->files();

        $primary = [];

        foreach ($builds->builds() as $build) {
            $primary[] = $this->downloadable($build);
        }

        $all = [];

        foreach ($files as $build) {
            $all[] = $this->downloadable($build);
        }

        return Inertia::render('Launcher/Index', [
            // none: nothing published (or the manifest is missing/invalid) | published
            'state' => $files === [] ? 'none' : 'published',
            'release' => $manifest === null ? null : [
                'version' => $manifest['version'],
                'commit' => $manifest['commit'],
                'builtAt' => $manifest['builtAt'],
            ],
            'builds' => $primary,
            'files' => $all,
            'server' => [
                'name' => (string) config('app.name'),
                // The address the visitor reached this server on is the one the launcher
                // has to be pointed at (the LAN address, not an internal container name).
                // A forged Host header must not become the pairing address: only the
                // configured app host is trusted, otherwise the APP_URL host wins.
                'url' => $this->pairingUrl($request),
                'version' => (string) config('app.version'),
            ],
            // Only preselects/highlights a build; every OS is listed regardless.
            'detectedOs' => $this->detectedOs((string) $request->userAgent()),
            // The start page below the download buttons: every server that is
            // live right now, each with the one action that gets a player onto
            // it (the launcher for recipes this build can install, the game's
            // own join URL otherwise).
            'servers' => $this->liveServers($modules),
        ]);
    }

    /**
     * The best published build for the visitor's system, so the shell's
     * "Launcher herunterladen" button starts the download instead of landing
     * on a second page first. No build at all returns to the page, which then
     * explains the empty state.
     */
    public function latest(Request $request, LauncherBuilds $builds): RedirectResponse
    {
        $available = $builds->builds();

        if ($available === []) {
            return redirect()->route('launcher.index');
        }

        $os = $this->detectedOs((string) $request->userAgent());
        $build = null;

        if ($os !== null) {
            foreach ($available as $candidate) {
                if ($candidate['os'] === $os) {
                    $build = $candidate;
                    break;
                }
            }

            // A known system without a build must not receive another system's
            // file silently; the page names the gap instead.
            if ($build === null) {
                return redirect()->route('launcher.index');
            }
        }

        $build ??= $available[0];

        return redirect()->route('launcher.download', ['filename' => $build['name']]);
    }

    public function download(string $filename, LauncherBuilds $builds): StreamedResponse
    {
        // The route pattern already excludes separators; the comparison keeps it true even
        // if the pattern ever loosens, and a manifest name can never contain a directory.
        abort_unless($filename === basename($filename), 404);

        $build = $builds->find($filename);
        abort_if($build === null, 404);

        // One open handle, size- and checksum-verified after opening, then streamed from
        // that same handle: the download is exactly the bytes that were verified, so a
        // file swapped between check and response cannot slip through (no TOCTOU).
        $handle = $builds->open($build);
        abort_if($handle === null, 404, 'The launcher build is unavailable or does not match its checksum.');

        return response()->streamDownload(function () use ($handle): void {
            try {
                fpassthru($handle);
            } finally {
                fclose($handle);
            }
        }, $build['name'], [
            'Content-Type' => $this->contentType($build['name']),
            'Content-Length' => (string) $build['size'],
            'X-Checksum-Sha256' => $build['sha256'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /**
     * Every live server of the aggregated browser, reduced to the one action a
     * visitor needs: join it.
     *
     * A row whose `install` block carries a launcher join links into the
     * launcher (`lanomat://join/<slug>`) with the server address or join URL.
     * That one deep link runs the install wizard when the game is missing and
     * the launch when it is installed. The decision is the server projection's
     * ({@see ServerListProjection::dto()}), so the launcher page, the server
     * browser and the games page cannot disagree — a published, lineup-allowed
     * recipe the launcher can install is all it takes, whether it ships with
     * LANoMAT or was shared by a participant. Every other server keeps its own
     * join URL (`steam://`, `ut2004://`, or the game's browser entry point),
     * exactly like the server browser does.
     *
     * GameServers is optional: without it the start page simply has no live
     * list (the section says so), never a broken request.
     *
     * @return list<array<string, mixed>>
     */
    private function liveServers(Modules $modules): array
    {
        if (! $modules->enabled(Module::GameServers)) {
            return [];
        }

        $servers = [];

        foreach (ServerListProjection::forGlobalBrowser() as $row) {
            $status = (string) ($row['status'] ?? '');

            // "Live" means joinable: a starting or failed server has nothing to
            // join yet, and a permanent offline world is not "running now".
            if (! in_array($status, ['ready', 'not_responding'], true)) {
                continue;
            }

            $query = is_array($row['query'] ?? null) ? $row['query'] : [];
            $event = is_array($row['event'] ?? null) ? $row['event'] : null;
            $install = is_array($row['install'] ?? null) ? $row['install'] : null;
            $joinUrl = $this->joinUrl($row);
            $launcherJoin = is_string($install['launcherJoinUrl'] ?? null) ? $install['launcherJoinUrl'] : null;

            $join = null;

            if ($launcherJoin !== null) {
                $join = ['href' => $launcherJoin, 'launcher' => true];
            } elseif ($joinUrl !== null) {
                $join = ['href' => $joinUrl, 'launcher' => false];
            }

            $servers[] = [
                'id' => $row['id'] ?? null,
                'title' => (string) ($row['title'] ?? ''),
                'game' => is_string($row['game'] ?? null) ? $row['game'] : null,
                'gameSlug' => is_string($row['gameSlug'] ?? null) ? $row['gameSlug'] : null,
                'status' => $status,
                'players' => isset($query['players']) ? (int) $query['players'] : null,
                'maxPlayers' => isset($query['maxPlayers']) ? (int) $query['maxPlayers'] : null,
                'map' => is_string($query['map'] ?? null) && $query['map'] !== '' ? $query['map'] : null,
                'address' => $this->hostPort($row['address'] ?? null, $row['port'] ?? null),
                'permanent' => (bool) ($row['permanent'] ?? false),
                'event' => is_string($event['name'] ?? null)
                    ? ['name' => $event['name'], 'slug' => (string) ($event['slug'] ?? '')]
                    : null,
                'join' => $join,
            ];
        }

        return $servers;
    }

    /**
     * The first per-game join URL (`steam://`, `ut2004://`, …), else a connect
     * string that is itself a deep link. A plain `address:port` is copy data,
     * not a URL the browser can open.
     *
     * @param  array<string, mixed>  $row
     */
    private function joinUrl(array $row): ?string
    {
        foreach (is_array($row['joinOptions'] ?? null) ? $row['joinOptions'] : [] as $option) {
            $url = is_array($option) ? ($option['url'] ?? null) : null;

            if (is_string($url) && JoinLinkTemplates::isAllowedUrl($url)) {
                return $url;
            }
        }

        $connect = $row['connectString'] ?? null;

        return ($row['connectIsLink'] ?? false) === true
            && is_string($connect)
            && JoinLinkTemplates::isAllowedUrl($connect)
                ? $connect
                : null;
    }

    /** `host:port` (IPv6 bracketed), the form the launcher's launch uses; null when either part is missing. */
    private function hostPort(mixed $address, mixed $port): ?string
    {
        if (! is_string($address) || trim($address) === '' || ! is_int($port) || $port < 1 || $port > 65535) {
            return null;
        }

        $address = trim($address);

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$address.']:'.$port
            : $address.':'.$port;
    }

    /**
     * The pairing address shown on the page: the request origin only when its host matches
     * the configured APP_URL host (a forged Host header must not be handed to the launcher),
     * otherwise the configured APP_URL itself.
     */
    private function pairingUrl(Request $request): string
    {
        $configured = rtrim((string) config('app.url'), '/');
        $configuredHost = parse_url($configured, PHP_URL_HOST);
        $requestHost = $request->getHost();

        if (is_string($configuredHost) && $configuredHost !== ''
            && $requestHost !== '' && strcasecmp($configuredHost, $requestHost) === 0) {
            return $request->getSchemeAndHttpHost();
        }

        return $configured !== '' ? $configured : $request->getSchemeAndHttpHost();
    }

    /**
     * A manifest entry plus the URL it is served under.
     *
     * @param  array{name: string, os: string, arch: string, kind: string, variant: string, size: int, sha256: string, signed: bool|null}  $build
     * @return array{name: string, os: string, arch: string, kind: string, variant: string, size: int, sha256: string, signed: bool|null, url: string}
     */
    private function downloadable(array $build): array
    {
        return $build + ['url' => route('launcher.download', ['filename' => $build['name']], absolute: false)];
    }

    /** Content type of the download, from the file extension (the name is manifest-validated). */
    private function contentType(string $name): string
    {
        return match (true) {
            str_ends_with($name, '.zip') => 'application/zip',
            str_ends_with($name, '.exe') => 'application/vnd.microsoft.portable-executable',
            str_ends_with($name, '.tar.gz'), str_ends_with($name, '.tgz'), str_ends_with($name, '.gz') => 'application/gzip',
            default => 'application/octet-stream',
        };
    }

    /**
     * Which desktop OS the visitor's user-agent names. Deliberately local: the
     * GameRecipes module must not reach into another optional module for three lines.
     *
     * @return 'linux'|'windows'|'macos'|null
     */
    private function detectedOs(string $userAgent): ?string
    {
        return match (true) {
            stripos($userAgent, 'Windows') !== false => 'windows',
            stripos($userAgent, 'Macintosh') !== false || stripos($userAgent, 'Mac OS X') !== false => 'macos',
            // Android and ChromeOS also say Linux but have no build: treat them as unknown.
            stripos($userAgent, 'Android') !== false || stripos($userAgent, 'CrOS') !== false => null,
            stripos($userAgent, 'Linux') !== false || stripos($userAgent, 'X11') !== false => 'linux',
            default => null,
        };
    }
}
