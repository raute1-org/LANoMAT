<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\GameRecipes\Support\LauncherBuilds;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\Support\ClientBuild;
use App\Modules\WebVoice\Support\ClientBuilds;
use App\Modules\WebVoice\Support\VisitorPlatform;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one download page of the LANoMAT app (`/app`): everything a participant can install on their own
 * machine, in one view. While two builds exist (the voice/desktop app and the launcher) it lists both
 * as separate "downloads"; once only one remains the same page shows one card without a rebuild.
 *
 * It lives outside the modules on purpose: it is a shell-level entry that composes two optional modules
 * (WebVoice and GameRecipes), each only when that module has routes. The files themselves are still served
 * by the modules' own download routes, which stay unchanged.
 */
class AppDownloadController extends Controller
{
    public function show(Request $request, Modules $modules): Response
    {
        $downloads = [];

        if ($modules->routable(Module::WebVoice)) {
            Gate::forUser($this->user($request))->authorize('downloadClient', VoiceClientToken::class);
            $downloads[] = $this->voiceDownload(app(ClientBuilds::class));
        }

        if ($modules->routable(Module::GameRecipes)) {
            $downloads[] = $this->launcherDownload(app(LauncherBuilds::class));
        }

        return Inertia::render('App/Download', [
            'downloads' => $downloads,
            'server' => [
                'name' => (string) config('app.name'),
                // The address the launcher has to be pointed at: only the configured app host is trusted,
                // a forged Host header must not become the pairing address.
                'url' => $this->pairingUrl($request),
                'version' => ClientBuilds::serverVersion(),
            ],
            // Preselects a tab only; every platform is listed regardless.
            'detectedOs' => VisitorPlatform::os((string) $request->userAgent()),
            'pairUrl' => $modules->routable(Module::WebVoice) ? '/talk/connect' : null,
            'labels' => trans('app_download'),
        ]);
    }

    /** The old page URLs (`/launcher`, `/talk/client`) still work: bookmarks, Discord posts, deep links. */
    public function legacy(): RedirectResponse
    {
        return redirect()->route('app.download');
    }

    /** @return array<string, mixed> */
    private function voiceDownload(ClientBuilds $builds): array
    {
        $manifest = $builds->current();
        $hasBuilds = $manifest !== null && $manifest->builds !== [];
        $matches = $hasBuilds && $builds->matchesServer();

        return [
            'key' => 'voice',
            // none: nothing published | mismatch: built for another native-client API version | current (compatible)
            'state' => ! $hasBuilds ? 'none' : ($matches ? 'current' : 'mismatch'),
            'exact' => $hasBuilds && $builds->matchesExactly(),
            'release' => $manifest === null ? null : [
                'version' => $manifest->appVersion,
                'commit' => $manifest->commit,
                'apiVersion' => $manifest->apiVersion,
                'builtAt' => $manifest->builtAt->toIso8601String(),
            ],
            'builds' => collect($manifest->builds ?? [])->map(fn (ClientBuild $build): array => $build->toArray() + [
                'url' => route('webvoice.client.download', ['file' => $build->name], absolute: false),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function launcherDownload(LauncherBuilds $builds): array
    {
        $manifest = $builds->manifest();
        $files = [];

        foreach ($builds->files() as $build) {
            $files[] = $build + ['url' => route('launcher.download', ['filename' => $build['name']], absolute: false)];
        }

        return [
            'key' => 'launcher',
            // The launcher manifest has no server-version coupling: it is either published or not.
            'state' => $files === [] ? 'none' : 'current',
            'exact' => true,
            'release' => $manifest === null ? null : [
                'version' => $manifest['version'],
                'commit' => $manifest['commit'],
                'builtAt' => $manifest['builtAt'],
            ],
            'builds' => $files,
        ];
    }

    /**
     * The request origin only when its host matches the configured APP_URL host, otherwise the configured
     * APP_URL itself.
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

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
