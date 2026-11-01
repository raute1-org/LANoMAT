<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Modules\Presence\Contracts\VoiceSightingSource;

/**
 * The voice backends that are switched on, as {@see VoiceSightingSource}s. The composition root
 * (AppServiceProvider) decides which ones exist from the module toggles, so Presence itself never
 * names Discord, Voice or WebVoice.
 */
final readonly class VoiceSources
{
    /**
     * @param  list<VoiceSightingSource>  $sources
     */
    public function __construct(private array $sources = []) {}

    /**
     * @return list<VoiceSightingSource>
     */
    public function all(): array
    {
        return $this->sources;
    }
}
