<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\ResumableUploads;
use App\Modules\Files\Support\UploadQuota;
use App\Modules\GameRecipes\Actions\ShareGame;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Http\Requests\ShareGameRequest;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\LauncherLineup;
use App\Modules\GameRecipes\Support\PortableRecipeBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Spiel teilen": a participant picks an archive they uploaded (or an approved
 * one), describes the game, and gets a draft portable recipe.
 *
 * The page shows the upload surfaces of the current LAN, the participant's own
 * files, and their existing shares with the pinned archive and the pull request
 * state. The controller stays thin: {@see ShareGame} owns every refusal.
 */
final class GameShareController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    public function index(Request $request, CurrentEvent $currentEvent, LauncherLineup $lineup): Response
    {
        $user = $this->authUser($request);
        $event = $currentEvent->get();

        /** @var Collection<int, SharedFile> $files */
        $files = $event === null ? new Collection : SharedFile::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->whereIn('visibility', [FileVisibility::Pending->value, FileVisibility::Approved->value])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $shares = GameRecipe::query()
            ->where('contributor_user_id', $user->id)
            // One query for the pins: the share list must not run one per row.
            ->with('files.sharedFile')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('Games/Share', [
            'event' => $event === null ? null : ['name' => $event->name, 'slug' => $event->slug],
            'upload' => $event === null ? null : $this->uploadDto($event),
            'quota' => $event === null ? null : $this->quotaDto($event, $user),
            'files' => $files->map(fn (SharedFile $file): array => $this->fileDto($file))->values()->all(),
            'shares' => $shares->map(fn (GameRecipe $recipe): array => $this->shareDto($recipe, $lineup))->values()->all(),
            'selectedFileId' => $this->selectedFileId($request, $files),
            'limits' => [
                'titleMax' => ShareGameRequest::TITLE_MAX,
                'summaryMax' => ShareGameRequest::SUMMARY_MAX,
                'executableMax' => ShareGameRequest::EXECUTABLE_MAX,
                'archiveExtensions' => PortableRecipeBuilder::ARCHIVE_EXTENSIONS,
            ],
            // The ResumableUploader speaks the files page labels; they stay one
            // translation set instead of a second copy on this page.
            'uploadLabels' => $this->uploadLabels(),
        ]);
    }

    public function store(ShareGameRequest $request, ShareGame $action): RedirectResponse
    {
        $this->authorize('create', GameRecipe::class);

        $recipe = $action->handle($this->authUser($request), $request->validated());
        $message = (string) __('game_share.flash.created', ['title' => $recipe->title]);

        // One success message: the Inertia toast the page renders and
        // announces. A second session flash would be shown twice.
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('games.share');
    }

    /**
     * The selected `?file=` only counts when it is one of this page's own
     * files: a foreign or rejected id never reaches the client.
     *
     * @param  Collection<int, SharedFile>  $files
     */
    private function selectedFileId(Request $request, Collection $files): ?int
    {
        $selected = filter_var($request->query('file'), FILTER_VALIDATE_INT);

        if ($selected === false) {
            return null;
        }

        return $files->contains('id', $selected) ? (int) $selected : null;
    }

    /**
     * How the page uploads: `resumable` (tus straight to the file host) or
     * `form` (post through the app), plus the file routes the uploader uses.
     *
     * @return array{mode: string, ticketUrl: string|null, storeUrl: string, maxFileBytes: int}
     */
    private function uploadDto(Event $event): array
    {
        $resumable = ResumableUploads::enabled();

        return [
            'mode' => $resumable ? 'resumable' : 'form',
            'ticketUrl' => $resumable ? route('files.uploads.ticket', ['event' => $event->slug], false) : null,
            'storeUrl' => route('files.store', ['event' => $event->slug], false),
            'maxFileBytes' => $resumable
                ? UploadQuota::maxFileBytes()
                : (int) config('files.max_upload_mb') * 1024 * 1024,
        ];
    }

    /** @return array{usedBytes: int, capBytes: int} */
    private function quotaDto(Event $event, User $user): array
    {
        return [
            'usedBytes' => UploadQuota::usedBytes($event->id, $user->id),
            'capBytes' => UploadQuota::capBytes($user),
        ];
    }

    /**
     * @return array{id: int, name: string, sizeBytes: int, visibility: string, createdAt: string, usable: bool}
     */
    private function fileDto(SharedFile $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'sizeBytes' => $file->size_bytes,
            'visibility' => $file->visibility->value,
            'createdAt' => ($file->created_at ?? now())->toIso8601String(),
            'usable' => PortableRecipeBuilder::isShareableArchive($file->original_name),
            'unusableReason' => $this->unusableReason($file->original_name),
        ];
    }

    /**
     * Why a real archive still cannot be shared (its name), in the words the
     * share action would refuse it with; null for a usable file and for a
     * file that is no archive at all (the list explains that case itself).
     */
    private function unusableReason(string $name): ?string
    {
        if (! PortableRecipeBuilder::isArchiveName($name)) {
            return null;
        }

        if (PortableRecipeBuilder::hasUnsafeArchiveName($name)) {
            return (string) __('game_share.errors.file_name');
        }

        if (! PortableRecipeBuilder::hasReliableMatchPattern($name)) {
            return (string) __('game_share.errors.file_name_pattern');
        }

        return null;
    }

    /**
     * One share row: status, lineup, the pinned archive and the pull request.
     *
     * @return array<string, mixed>
     */
    private function shareDto(GameRecipe $recipe, LauncherLineup $lineup): array
    {
        $pinned = $recipe->files->first(
            static fn (GameRecipeFile $file): bool => $file->role === RecipeFileRole::Base
                && $file->sharedFile instanceof SharedFile,
        );
        $shared = $pinned?->sharedFile;

        return [
            'slug' => $recipe->slug,
            'title' => $recipe->title,
            'status' => $recipe->status->value,
            'statusLabel' => $recipe->status->label(),
            'inLineup' => (bool) $recipe->in_lineup,
            // A shared game appears in the library only once it is published
            // and the lineup allows it; before that the page has no link.
            'gameUrl' => $recipe->status === RecipeStatus::Published && $lineup->allows($recipe->slug)
                ? '/games/'.$recipe->slug
                : null,
            'file' => $shared instanceof SharedFile
                ? ['name' => $shared->original_name, 'visibility' => $shared->visibility->value]
                : null,
            'pullRequest' => $recipe->pullRequest(),
            'updatedAt' => ($recipe->updated_at ?? now())->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    private function uploadLabels(): array
    {
        $labels = trans('files.page');

        return is_array($labels) ? array_map('strval', $labels) : [];
    }
}
