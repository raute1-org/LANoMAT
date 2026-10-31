<?php

namespace App\Modules\Files\Actions;

use App\Models\User;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\FileStores;
use Illuminate\Support\Facades\Gate;

class RejectSharedFile
{
    public function __construct(private readonly FileStores $stores) {}

    /**
     * Flips a pending file to Rejected, stamps the reviewer and removes the
     * bytes from the store (a rejected, possibly malicious executable must not
     * linger on the file host). The row stays for the moderation record and
     * keeps counting against the uploader's quota; it is invisible to every
     * participant and can no longer be downloaded or approved (re-upload
     * instead). Fails closed: if the store cannot be reached the file keeps
     * its status and the FileException propagates, so the reject can be
     * retried. Authorized in-Action against SharedFilePolicy::reject
     * (isHelper()), mirroring ApproveSharedFile.
     *
     * @throws FileException
     */
    public function handle(SharedFile $file, User $actor): SharedFile
    {
        Gate::forUser($actor)->authorize('reject', $file);

        $this->stores->for($file->disk)->delete($file->path);

        $file->forceFill([
            'visibility' => FileVisibility::Rejected,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);
        $file->save();

        return $file;
    }
}
