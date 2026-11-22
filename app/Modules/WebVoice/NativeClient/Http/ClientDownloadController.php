<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\AppDownloadController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\Support\ClientBuilds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The file route of the native voice client's download. The page that lists the builds is the unified
 * `/app` page ({@see AppDownloadController}). The set of files is
 * whatever the validated manifest lists ({@see ClientBuilds}); a name from the request is only ever
 * compared with that list, never turned into a path.
 */
class ClientDownloadController extends Controller
{
    public function download(Request $request, string $file, ClientBuilds $builds): BinaryFileResponse
    {
        Gate::forUser($this->user($request))->authorize('downloadClient', VoiceClientToken::class);

        $build = $builds->find($file);
        $path = $build === null ? null : $builds->pathFor($build);

        // Unknown to the manifest, gone from disk or changed in size: indistinguishable 404s.
        abort_if($build === null || $path === null || ! is_file($path), 404);

        return response()->download($path, $build->name, [
            'Content-Type' => $build->contentType(),
            'X-Checksum-Sha256' => $build->sha256,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
