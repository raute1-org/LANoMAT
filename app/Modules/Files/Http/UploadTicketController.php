<?php

declare(strict_types=1);

namespace App\Modules\Files\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Actions\IssueUploadTicket;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\ResumableUploads;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Step 1 of a resumable upload: the browser asks for a ticket for one file.
 * The user, the event and the rules are taken from the session and the route,
 * never from the body.
 */
class UploadTicketController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    public function __invoke(Request $request, Event $event, IssueUploadTicket $action): JsonResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $this->authorize('create', SharedFile::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'size' => ['required', 'integer', 'min:1'],
            'type' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $issued = $action->handle($event, $this->authUser($request), $data['name'], (int) $data['size'], $data['type'] ?? null);
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
}
