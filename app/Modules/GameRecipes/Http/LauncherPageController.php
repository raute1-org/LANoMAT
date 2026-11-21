<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Http;

use App\Http\Controllers\Controller;
use App\Modules\GameRecipes\Support\LauncherBuilds;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public `/launcher` page and the file route behind it: one download per OS from
 * the validated launcher manifest, with checksums, install steps and the address of
 * this server to pair with.
 *
 * Both routes are reachable without a login (a fresh PC installs the launcher before
 * its owner signs in) and carry no user data. The set of files is whatever
 * {@see LauncherBuilds} lists; a name from the request is only ever compared with that
 * list, never used as a path.
 */
class LauncherPageController extends Controller
{
    public function index(Request $request, LauncherBuilds $builds): Response
    {
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
        ]);
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
