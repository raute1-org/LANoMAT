<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Domain;

use RuntimeException;

/**
 * The SFU could not be reached, answered with an error, or is not configured. Callers decide:
 * the lifecycle job treats an unreachable server as "someone may be in there", the controller
 * answers 503 with a German message.
 */
class WebVoiceUnavailable extends RuntimeException {}
