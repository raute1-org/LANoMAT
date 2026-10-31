<?php

declare(strict_types=1);

namespace App\Modules\Files\Http;

use App\Http\Controllers\Controller;
use App\Modules\Files\Actions\AuthorizeTusUpload;
use App\Modules\Files\Actions\FinalizeTusUpload;
use App\Modules\Files\Actions\ReleaseUploadReservation;
use App\Modules\Files\Enums\UploadReservationStatus;
use App\Modules\Files\Models\UploadReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives tusd's HTTP hooks (relayed by the file host's nginx, which adds the
 * shared secret; see VerifyTusHookSecret). Plain JSON in and out. Only the
 * hooks the runbook enables are handled; any other type gets an empty answer.
 */
class TusHookController extends Controller
{
    public function __invoke(
        Request $request,
        AuthorizeTusUpload $authorize,
        FinalizeTusUpload $finalize,
        ReleaseUploadReservation $release,
    ): JsonResponse {
        $type = $request->input('Type');
        $upload = $request->input('Event.Upload');
        $upload = is_array($upload) ? $upload : [];

        $response = match ($type) {
            'pre-create' => $authorize->handle($upload),
            'post-finish' => $this->done(fn () => $finalize->handle($upload)),
            'post-terminate' => $this->done(fn () => $this->terminated($upload, $release)),
            default => [],
        };

        // An empty array must be a JSON object: tusd decodes the answer into a struct.
        return response()->json((object) $response)->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function done(callable $work): array
    {
        $work();

        return [];
    }

    /** @param  array<string, mixed>  $upload */
    private function terminated(array $upload, ReleaseUploadReservation $release): void
    {
        $uploadId = $upload['ID'] ?? null;

        if (! is_string($uploadId) || $uploadId === '') {
            return;
        }

        $reservation = UploadReservation::query()
            ->where('upload_id', $uploadId)
            ->where('status', UploadReservationStatus::Uploading)
            ->first();

        if ($reservation !== null) {
            $release->handle($reservation);
        }
    }
}
