<?php

namespace App\Modules\Discord\Http;

use App\Modules\Discord\Support\VoicePresenceProjection;
use Illuminate\Http\JsonResponse;

/**
 * Public read of current Discord voice occupancy (No-PII, mapped names only).
 * Public like the other participant read surfaces; the projection itself is
 * the privacy boundary.
 */
class VoicePresenceController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['channels' => VoicePresenceProjection::current()]);
    }
}
