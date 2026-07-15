<?php

namespace App\Modules\Lfg\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Lfg\Actions\CreateLfgPost;
use App\Modules\Lfg\Actions\DeleteLfgPost;
use App\Modules\Lfg\Exceptions\LfgException;
use App\Modules\Lfg\Http\Requests\CreateLfgPostRequest;
use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LfgController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    /**
     * The participant LFG board: every currently active (non-expired) post
     * for the event, newest first, with a per-viewer `mine` flag computed
     * from the request's authenticated user — never from client input.
     */
    public function index(Request $request, Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $userId = $request->user()?->id;

        $posts = LfgPost::query()
            ->where('event_id', $event->id)
            ->active()
            ->with('user')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Lfg/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'posts' => $posts->map(fn (LfgPost $post): array => $this->postDto($post, $userId))->all(),
            'labels' => trans('lfg.page'),
        ]);
    }

    public function store(CreateLfgPostRequest $request, Event $event, CreateLfgPost $action): RedirectResponse
    {
        $this->authorize('create', LfgPost::class);

        try {
            $action->handle($event, $this->authUser($request), $request->validated());
        } catch (LfgException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey)]);

            return back();
        }

        return back();
    }

    public function destroy(Request $request, LfgPost $lfgPost, DeleteLfgPost $action): RedirectResponse
    {
        $this->authorize('delete', $lfgPost);

        $action->handle($lfgPost);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function postDto(LfgPost $post, ?int $userId): array
    {
        return [
            'id' => $post->id,
            'game' => $post->game,
            'title' => $post->title,
            'body' => $post->body,
            'slotsNeeded' => $post->slots_needed,
            'userName' => $post->user?->name,
            'expiresAt' => $post->expires_at->toIso8601String(),
            'mine' => $userId !== null && $post->user_id === $userId,
        ];
    }
}
