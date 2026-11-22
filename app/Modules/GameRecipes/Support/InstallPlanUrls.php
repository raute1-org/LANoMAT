<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Models\User;
use App\Modules\Files\Models\SharedFile;

/**
 * How {@see BuildInstallPlan} addresses a LAN file for one kind of caller. The plan is the same for
 * everyone; only the download link differs: the MCP agent API has its own bearer route, the native
 * client gets a signed, short-lived file-host link.
 */
interface InstallPlanUrls
{
    /**
     * @return array{url: string, expires_at: string|null}
     */
    public function forFile(SharedFile $file, User $viewer): array;
}
