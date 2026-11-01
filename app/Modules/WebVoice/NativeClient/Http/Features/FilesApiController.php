<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http\Features;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Actions\DeleteSharedFile;
use App\Modules\Files\Actions\IssueUploadTicket;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\FileStores;
use App\Modules\Files\Support\ResumableUploads;
use App\Modules\Files\Support\UploadQuota;
use App\Modules\Files\Support\VisibleFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Native client: the shared files of an event. The list is the browser's (approved files plus the
 * caller's own pending ones), downloads go through the store's signed file-host link, deletes through
 * DeleteSharedFile and uploads through the same single-use tus ticket the browser gets. Raw storage
 * paths never leave the server and the moderation gate is untouched.
 */
class FilesApiController extends NativeFeatureController
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $user = $this->user($request);
        $this->requireVisible($event);

        $files = VisibleFiles::listFor($event, $user->id);

        return response()->json([
            'event_slug' => $event->slug,
            'files' => $files->map(fn (SharedFile $file): array => $this->fileDto($file, $user))->all(),
            'quota' => $this->quotaDto($event, $user),
            'upload' => $this->uploadDto(),
        ]);
    }

    /**
     * Usage and limits only (cheap to poll while an upload runs).
     */
    public function quota(Request $request, Event $event): JsonResponse
    {
        $user = $this->user($request);
        $this->requireVisible($event);

        return response()->json([
            'quota' => $this->quotaDto($event, $user),
            'upload' => $this->uploadDto(),
        ]);
    }

    /**
     * The signed, short-lived file-host URL for a file the caller may download. The bytes never pass
     * through the app. The legacy local store (no file host) is streamed instead, like the browser.
     */
    public function download(Request $request, SharedFile $sharedFile, FileStores $stores): JsonResponse|SymfonyResponse
    {
        $user = $this->user($request);
        $this->requireVisible($sharedFile->event()->first());

        $this->authorizeFor($user, 'download', $sharedFile);

        try {
            $response = $stores->for($sharedFile->disk)->download($sharedFile->path, $sharedFile->original_name);
        } catch (FileException) {
            return $this->domainError('files.errors.store_unavailable', [], 503);
        }

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        $url = $response->getTargetUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires = isset($query['expires']) && is_numeric($query['expires']) ? (int) $query['expires'] : null;

        return response()->json([
            'url' => $url,
            'name' => $sharedFile->original_name,
            'size_bytes' => $sharedFile->size_bytes,
            'expires_at' => $expires === null ? null : date(DATE_ATOM, $expires),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, SharedFile $sharedFile, DeleteSharedFile $action): JsonResponse|Response
    {
        $user = $this->user($request);
        $this->requireVisible($sharedFile->event()->first());

        $this->authorizeFor($user, 'delete', $sharedFile);

        try {
            $action->handle($sharedFile);
        } catch (FileException $exception) {
            return $this->domainError($exception->translationKey, [], 503);
        }

        return response()->noContent();
    }

    /**
     * Step 1 of a tus upload, with the exact payload of the browser ticket route.
     */
    public function ticket(Request $request, Event $event, IssueUploadTicket $action): JsonResponse
    {
        $user = $this->user($request);
        $this->requireVisible($event);

        $this->authorizeFor($user, 'create', SharedFile::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'size' => ['required', 'integer', 'min:1'],
            'type' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $issued = $action->handle($event, $user, $data['name'], (int) $data['size'], $data['type'] ?? null);
        } catch (FileException $exception) {
            return response()->json([
                'code' => $exception->code(),
                'message' => trans($exception->translationKey),
            ], 422);
        }

        return response()->json([
            'endpoint' => ResumableUploads::endpoint(),
            'ticket' => $issued['ticket'],
            'chunkSize' => ResumableUploads::chunkBytes(),
            'expiresIn' => ResumableUploads::ticketTtlSeconds(),
            'name' => $issued['reservation']->original_name,
            'reservationId' => $issued['reservation']->id,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array{used_bytes: int, cap_bytes: int, remaining_bytes: int}
     */
    private function quotaDto(Event $event, User $user): array
    {
        $used = UploadQuota::usedBytes($event->id, $user->id);
        $cap = UploadQuota::capBytes();

        return [
            'used_bytes' => $used,
            'cap_bytes' => $cap,
            'remaining_bytes' => max(0, $cap - $used),
        ];
    }

    /**
     * Whether this deployment accepts resumable uploads (the only upload path of the native client)
     * and the per-file limit.
     *
     * @return array{resumable: bool, max_file_bytes: int}
     */
    private function uploadDto(): array
    {
        return [
            'resumable' => ResumableUploads::enabled(),
            'max_file_bytes' => UploadQuota::maxFileBytes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileDto(SharedFile $file, User $user): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'size_bytes' => $file->size_bytes,
            'uploader_name' => $file->uploader?->name,
            'visibility' => $file->visibility->value,
            'created_at' => ($file->created_at ?? now())->toIso8601String(),
            'mine' => $file->user_id === $user->id,
            'can_download' => Gate::forUser($user)->allows('download', $file),
            'can_delete' => Gate::forUser($user)->allows('delete', $file),
        ];
    }
}
