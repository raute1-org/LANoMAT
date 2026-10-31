<?php

namespace App\Http\Middleware;

use App\Support\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the CSP header (see {@see ContentSecurityPolicy}) according to
 * `security.csp.mode`: `report-only` (default, never blocks), `enforce` or
 * `off`. Generates a fresh nonce per request and shares it with Laravel's
 * Vite/Livewire/Filament asset rendering via `Vite::useCspNonce()` before the
 * response is rendered.
 *
 * Registered on the `web` group (profile `app`) and, because Filament panels
 * build their own stack and never run `web`, on the admin panel with the
 * `panel` profile: `AddContentSecurityPolicy::class.':panel'`.
 */
class AddContentSecurityPolicy
{
    public function handle(Request $request, Closure $next, string $profile = ContentSecurityPolicy::PROFILE_APP): Response
    {
        $mode = config('security.csp.mode');

        if ($mode === 'off') {
            return $next($request);
        }

        $nonce = base64_encode(random_bytes(16));
        Vite::useCspNonce($nonce);

        $policy = new ContentSecurityPolicy(
            nonce: $nonce,
            profile: $profile,
            reverbConnectSource: ContentSecurityPolicy::reverbSource(
                config('broadcasting.connections.reverb.options.host'),
                config('broadcasting.connections.reverb.options.port'),
                config('broadcasting.connections.reverb.options.scheme'),
            ),
            formActionHosts: array_values(array_filter((array) config('security.csp.form_action_hosts'), is_string(...))),
            reportPath: (string) config('security.csp.report_path', '/csp-report'),
            viteDevOrigin: $this->viteDevOrigin(),
            webVoiceConnectSource: ContentSecurityPolicy::webVoiceSource(config('webvoice.url')),
            fileHostConnectSource: ContentSecurityPolicy::fileHostSource(config('files.store') === 'host' ? config('files.tus.endpoint') : null),
        );

        $response = $next($request);

        // Anything but an explicit "enforce" never blocks (fail safe).
        $header = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';

        $response->headers->set($header, $policy->header());
        $response->headers->set('Reporting-Endpoints', $policy->reportingEndpoints());

        return $response;
    }

    /** `npm run dev` serves assets from the hot-file origin; allow it so dev noise stays out of the log. */
    private function viteDevOrigin(): ?string
    {
        if (app()->isProduction()) {
            return null;
        }

        $hot = public_path('hot');

        if (! is_file($hot)) {
            return null;
        }

        $url = trim((string) file_get_contents($hot));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
