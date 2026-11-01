<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\Support\ClientBuild;
use App\Modules\WebVoice\Support\ClientBuilds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The download page for the native voice client and the file route behind it. The set of files is
 * whatever the validated manifest lists ({@see ClientBuilds}); a name from the request is only ever
 * compared with that list, never turned into a path.
 */
class ClientDownloadController extends Controller
{
    public function show(Request $request, ClientBuilds $builds): Response
    {
        Gate::forUser($this->user($request))->authorize('downloadClient', VoiceClientToken::class);

        $manifest = $builds->current();
        $hasBuilds = $manifest !== null && $manifest->builds !== [];
        $matches = $hasBuilds && $builds->matchesServer();

        return Inertia::render('WebVoice/ClientDownload', [
            // none: nothing published | mismatch: published for another server version | current
            'state' => ! $hasBuilds ? 'none' : ($matches ? 'current' : 'mismatch'),
            'server' => ['version' => ClientBuilds::serverVersion(), 'commit' => ClientBuilds::serverCommit()],
            'release' => $manifest === null ? null : [
                'version' => $manifest->appVersion,
                'commit' => $manifest->commit,
                'apiVersion' => $manifest->apiVersion,
                'builtAt' => $manifest->builtAt->toIso8601String(),
            ],
            'builds' => collect($manifest->builds ?? [])->map(fn (ClientBuild $build): array => $build->toArray() + [
                'url' => route('webvoice.client.download', ['file' => $build->name], absolute: false),
            ])->values(),
            // Preselects a tab only; every platform is listed regardless.
            'detectedOs' => $this->detectOs((string) $request->userAgent()),
            'labels' => trans('web_voice.client'),
        ]);
    }

    public function download(Request $request, string $file, ClientBuilds $builds): BinaryFileResponse
    {
        Gate::forUser($this->user($request))->authorize('downloadClient', VoiceClientToken::class);

        $build = $builds->find($file);
        $path = $build === null ? null : $builds->pathFor($build);

        // Unknown to the manifest, gone from disk or changed in size: indistinguishable 404s.
        abort_if($build === null || $path === null || ! is_file($path), 404);

        return response()->download($path, $build->name, [
            'Content-Type' => 'application/gzip',
            'X-Checksum-Sha256' => $build->sha256,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /** @return 'linux'|'windows'|'macos'|null */
    private function detectOs(string $userAgent): ?string
    {
        return match (true) {
            stripos($userAgent, 'Windows') !== false => 'windows',
            stripos($userAgent, 'Macintosh') !== false || stripos($userAgent, 'Mac OS X') !== false => 'macos',
            // Android and ChromeOS also say Linux, but have no build either way: treat them as unknown.
            stripos($userAgent, 'Android') !== false || stripos($userAgent, 'CrOS') !== false => null,
            stripos($userAgent, 'Linux') !== false || stripos($userAgent, 'X11') !== false => 'linux',
            default => null,
        };
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
