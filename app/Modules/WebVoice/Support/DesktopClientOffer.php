<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Support;

use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Http\Request;

/**
 * The small shared Inertia prop `desktopClient`: whether this visitor can be offered the desktop
 * app. `null` for guests and when the WebVoice module has no routes; otherwise the page URL plus
 * `available` (a GUI build for the visitor's OS exists AND matches the server version AND the
 * visitor is not on a phone or tablet). The manifest summary behind it is cached for 60 s.
 */
final class DesktopClientOffer
{
    public function __construct(private readonly Modules $modules, private readonly ClientBuilds $builds) {}

    /** @return array{url: string, version: string|null, os: string|null, available: bool}|null */
    public function for(Request $request): ?array
    {
        if ($request->user() === null || ! $this->modules->routable(Module::WebVoice)) {
            return null;
        }

        $agent = (string) $request->userAgent();
        $os = VisitorPlatform::os($agent);
        $offer = $this->builds->offer();

        return [
            'url' => route('webvoice.client', absolute: false),
            'version' => $offer['version'],
            'os' => $os,
            'available' => $offer['current'] && $os !== null && in_array($os, $offer['oses'], true) && ! VisitorPlatform::isMobile($agent),
        ];
    }
}
