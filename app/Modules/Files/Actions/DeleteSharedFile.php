<?php

namespace App\Modules\Files\Actions;

use App\Modules\Files\Models\SharedFile;
use Illuminate\Support\Facades\Storage;

class DeleteSharedFile
{
    /**
     * Deletes the stored file from disk before removing the row — policy
     * gate (owner or orga) is enforced by the caller via authorize('delete').
     */
    public function handle(SharedFile $file): void
    {
        Storage::disk($file->disk)->delete($file->path);
        $file->delete();
    }
}
