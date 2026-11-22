<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Http;

use App\Http\Controllers\AppDownloadController;
use App\Http\Controllers\Controller;
use App\Modules\GameRecipes\Support\LauncherBuilds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The launcher's file routes: `download-latest` (the best build for the visitor's system) and
 * `download/{filename}`. The page that lists the builds is the unified `/app` page
 * ({@see AppDownloadController}); `/launcher` redirects there.
 *
 * Both routes require login. The set of files is whatever
 * {@see LauncherBuilds} lists; a name from the request is only ever compared with that
 * list, never used as a path.
 */
class LauncherPageController extends Controller
{
    /**
     * The best published build for the visitor's system, so the shell's
     * "App herunterladen" entry can start the download instead of landing
     * on a second page first. No build at all returns to the page, which then
     * explains the empty state.
     */
    public function latest(Request $request, LauncherBuilds $builds): RedirectResponse
    {
        $available = $builds->builds();

        if ($available === []) {
            return redirect()->route('app.download');
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
                return redirect()->route('app.download');
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
