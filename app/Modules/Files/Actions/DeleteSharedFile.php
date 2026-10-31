<?php

namespace App\Modules\Files\Actions;

use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\FileStores;

class DeleteSharedFile
{
    public function __construct(private readonly FileStores $stores) {}

    /**
     * Removes the stored file from the store that holds it, then the row —
     * policy gate (owner or orga) is enforced by the caller via
     * authorize('delete'). Fails closed: when the store cannot be reached the
     * row is kept (so the delete can be retried) and the FileException
     * propagates; a file that is already gone from the store is fine.
     *
     * @throws FileException
     */
    public function handle(SharedFile $file): void
    {
        $this->stores->for($file->disk)->delete($file->path);
        $file->delete();
    }
}
