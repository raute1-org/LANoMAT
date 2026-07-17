<?php

namespace App\Modules\Files\Actions;

use App\Models\User;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Support\Facades\Gate;

class RejectSharedFile
{
    /**
     * Flips a pending file to Rejected and stamps the reviewer. The file
     * stays on disk and remains visible to the uploader and orga
     * (SharedFilePolicy::view()) but is hidden from every other
     * participant; pruning rejected files from storage is out of scope
     * here. Authorized in-Action against SharedFilePolicy::reject
     * (isHelper()), mirroring ApproveSharedFile.
     */
    public function handle(SharedFile $file, User $actor): SharedFile
    {
        Gate::forUser($actor)->authorize('reject', $file);

        $file->forceFill([
            'visibility' => FileVisibility::Rejected,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);
        $file->save();

        return $file;
    }
}
