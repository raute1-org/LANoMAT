<?php

namespace App\Modules\Files\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Actions\DeleteSharedFile;
use App\Modules\Files\Actions\UploadSharedFile;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\FileStores;
use App\Modules\Files\Support\ResumableUploads;
use App\Modules\Files\Support\UploadQuota;
use App\Modules\Files\Support\VisibleFiles;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

        $files = VisibleFiles::listFor($event, $userId);

        return Inertia::render('Files/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'files' => $files->map(fn (SharedFile $file): array => $this->fileDto($file, $userId))->all(),
            'labels' => trans('files.page'),
            'quota' => $this->quotaDto($event, $userId),
            'upload' => $this->uploadDto($event),
        ]);
    }

    public function store(Request $request, Event $event, UploadSharedFile $action): RedirectResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $this->authorize('create', SharedFile::class);

        if (ResumableUploads::enabled()) {
            // One code path with the file host: the bytes never pass through the app.
            Inertia::flash('toast', ['type' => 'error', 'message' => trans(FileException::useResumableUpload()->translationKey)]);

            return back();
        }

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

    /**
     * The app never streams the bytes of a file-host file: after the policy
     * allowed it the store answers with a redirect to a short-lived signed URL
     * (or, for the local store, an attachment stream).
     */
    public function download(Request $request, SharedFile $sharedFile, FileStores $stores): SymfonyResponse
    {
        $this->authorize('download', $sharedFile);

        try {
            return $stores->for($sharedFile->disk)->download($sharedFile->path, $sharedFile->original_name);
        } catch (FileException) {
            abort(503, trans('files.errors.store_unavailable'));
        }
    }

    public function destroy(Request $request, SharedFile $sharedFile, DeleteSharedFile $action): RedirectResponse
    {
        $this->authorize('delete', $sharedFile);

        try {
            $action->handle($sharedFile);
        } catch (FileException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey)]);
        }

        return back();
    }

    /**
     * The viewer's own used bytes (across every visibility plus open
     * resumable-upload reservations, so a pending or running upload already
     * counts against the same quota UploadSharedFile enforces) plus the configured per-event cap, so the participant page
     * can render a "used / cap" readout. Null for guests — there is nothing
     * to upload against without an account.
     *
     * @return array{usedBytes: int, capBytes: int}|null
     */
    private function quotaDto(Event $event, ?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        return [
            'usedBytes' => UploadQuota::usedBytes($event->id, $userId),
            'capBytes' => UploadQuota::capBytes(),
        ];
    }

    /**
     * How the page uploads: `resumable` (tus straight to the file host, with
     * the per-file limit) or `form` (legacy post through the app).
     *
     * @return array{mode: string, maxFileBytes: int, ticketUrl: string|null}
     */
    private function uploadDto(Event $event): array
    {
        if (ResumableUploads::enabled()) {
            return [
                'mode' => 'resumable',
                'maxFileBytes' => UploadQuota::maxFileBytes(),
                'ticketUrl' => route('files.uploads.ticket', ['event' => $event->slug], false),
            ];
        }

        return [
            'mode' => 'form',
            'maxFileBytes' => (int) config('files.max_upload_mb') * 1024 * 1024,
            'ticketUrl' => null,
        ];
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
