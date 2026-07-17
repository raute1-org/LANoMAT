<?php

namespace App\Modules\Files\Actions;

use App\Models\User;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Support\Facades\Gate;

class ApproveSharedFile
{
    /**
     * Flips a pending (or rejected) file to Approved and stamps the
     * reviewer, so it becomes visible to every participant via
     * SharedFilePolicy::view(). Authorized in-Action against
     * SharedFilePolicy::approve (isHelper()) — the Filament row action and
     * any future entry point both go through this single gate rather than
     * re-implementing the check.
     */
    public function handle(SharedFile $file, User $actor): SharedFile
    {
        Gate::forUser($actor)->authorize('approve', $file);

        $file->forceFill([
            'visibility' => FileVisibility::Approved,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);
        $file->save();

        return $file;
    }
}
