<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http\Api;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\FileStores;
use App\Modules\Files\Support\VisibleFiles;
use App\Modules\GameRecipes\Support\SafeText;
use App\Modules\Mcp\Support\AccessibleFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Bearer-authenticated file access for the installer: list per event, download one. */
class FileController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:120'],
            'query' => ['nullable', 'string', 'max:120'],
        ]);

        $event = Event::query()->publiclyVisible()->where('slug', $data['event'])->firstOrFail();
        $query = mb_strtolower((string) ($data['query'] ?? ''));

        $files = VisibleFiles::listFor($event, $this->authUser($request)->id)
            ->when($query !== '', fn ($collection) => $collection->filter(
                static fn (SharedFile $file): bool => str_contains(mb_strtolower($file->original_name), $query),
            ))
            ->values();

        return response()->json([
            'event_slug' => $event->slug,
            'data' => $files->map(fn (SharedFile $file): array => $this->dto($file, $this->authUser($request)))->all(),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function download(Request $request, SharedFile $sharedFile, FileStores $stores): Response
    {
        $user = $this->authUser($request);

        abort_if(Gate::forUser($user)->denies('download', $sharedFile), 403);

        AccessibleFiles::ensureVisible($sharedFile, $user);

        return $stores->for($sharedFile->disk)->download($sharedFile->path, $sharedFile->original_name);
    }

    /** @return array<string, mixed> */
    private function dto(SharedFile $file, User $user): array
    {
        return [
            'id' => $file->id,
            'name' => SafeText::fileName($file->original_name),
            'size_bytes' => $file->size_bytes,
            'visibility' => $file->visibility->value,
            'is_approved' => $file->visibility === FileVisibility::Approved,
            'mine' => $file->user_id === $user->id,
            'uploaded_at' => $file->created_at?->toIso8601String(),
            'download_url' => route('mcp.files.download', ['sharedFile' => $file->id], true),
        ];
    }
}
