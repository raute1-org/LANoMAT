<?php

namespace App\Modules\Files\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Actions\DeleteSharedFile;
use App\Modules\Files\Actions\UploadSharedFile;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilePageController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    /**
     * The participant file list: every approved file for the event, plus
     * (if authenticated) the viewer's own pending files, each flagged with
     * `mine` so the UI can show a "waiting for approval" indicator. The
     * `mine` flag is derived from the request's authenticated user — never
     * from client input.
     */
    public function index(Request $request, Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $userId = $request->user()?->id;

        $query = SharedFile::query()
            ->where('event_id', $event->id)
            ->with('uploader');

        $query->where(function ($builder) use ($userId) {
            $builder->where('visibility', FileVisibility::Approved);

            if ($userId !== null) {
                $builder->orWhere(function ($ownPending) use ($userId) {
                    $ownPending->where('user_id', $userId)
                        ->where('visibility', FileVisibility::Pending);
                });
            }
        });

        $files = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Files/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'files' => $files->map(fn (SharedFile $file): array => $this->fileDto($file, $userId))->all(),
            'labels' => trans('files.page'),
        ]);
    }

    public function store(Request $request, Event $event, UploadSharedFile $action): RedirectResponse
    {
        $this->authorize('create', SharedFile::class);

        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');
        abort_if($file === null, 422, 'No file was uploaded.');

        try {
            $action->handle($event, $this->authUser($request), $file);
        } catch (FileException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey)]);

            return back();
        }

        return back();
    }

    public function download(Request $request, SharedFile $sharedFile): StreamedResponse
    {
        $this->authorize('download', $sharedFile);

        return Storage::disk($sharedFile->disk)->download($sharedFile->path, $sharedFile->original_name);
    }

    public function destroy(Request $request, SharedFile $sharedFile, DeleteSharedFile $action): RedirectResponse
    {
        $this->authorize('delete', $sharedFile);

        $action->handle($sharedFile);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function fileDto(SharedFile $file, ?int $userId): array
    {
        return [
            'id' => $file->id,
            'originalName' => $file->original_name,
            'sizeBytes' => $file->size_bytes,
            'uploaderName' => $file->uploader?->name,
            'visibility' => $file->visibility->value,
            'createdAt' => ($file->created_at ?? now())->toIso8601String(),
            'mine' => $userId !== null && $file->user_id === $userId,
        ];
    }
}
