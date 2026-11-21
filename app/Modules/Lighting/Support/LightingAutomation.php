<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

use App\Support\Modules\Module;
use App\Support\Modules\Modules;

/**
 * Whether lighting may act at all: module on AND the master switch.
 */
final class LightingAutomation
{
    public static function active(): bool
    {
        return app(Modules::class)->enabled(Module::Lighting) && (bool) config('services.lighting.enabled');
    }
}
