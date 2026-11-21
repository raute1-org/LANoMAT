<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use App\Support\SafeText as CoreSafeText;

/**
 * The MCP module's name for the shared sanitiser ({@see CoreSafeText}).
 *
 * The implementation sits in `App\Support` because the GameRecipes module
 * projects the same untrusted labels and upload names; importing it from here
 * would make one optional module depend on another.
 */
final class SafeText extends CoreSafeText {}
