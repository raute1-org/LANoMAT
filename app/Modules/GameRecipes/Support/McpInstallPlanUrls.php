<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Models\User;
use App\Modules\Files\Models\SharedFile;

/** The agent API's links: its own bearer-authenticated download route (the historic behaviour). */
final class McpInstallPlanUrls implements InstallPlanUrls
{
    public function forFile(SharedFile $file, User $viewer): array
    {
        return [
            'url' => route('mcp.files.download', ['sharedFile' => $file->id], true),
            'expires_at' => null,
        ];
    }
}
