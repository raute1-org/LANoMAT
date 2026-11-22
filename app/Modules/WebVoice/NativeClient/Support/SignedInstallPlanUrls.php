<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

use App\Models\User;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\FileStores;
use App\Modules\GameRecipes\Support\InstallPlanUrls;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The native client's plan links: the same short-lived, signed file-host URL the
 * `GET files/{sharedFile}/download` route hands out, minted at plan time so the client can fetch
 * the bytes without a bearer. Minting goes through SharedFilePolicy::download for the viewer, so a
 * plan can never carry a link the web page would not give the same user.
 *
 * Where no signed link exists (the legacy local store streams through the app, the store is down,
 * the policy refuses) the link is the bearer download route itself, which decides again per
 * request. No storage path ever appears in a plan.
 */
final class SignedInstallPlanUrls implements InstallPlanUrls
{
    public function __construct(private readonly FileStores $stores) {}

    public function forFile(SharedFile $file, User $viewer): array
    {
        $fallback = ['url' => route('voice-client.v1.files.download', ['sharedFile' => $file->id], true), 'expires_at' => null];

        if (Gate::forUser($viewer)->denies('download', $file)) {
            return $fallback;
        }

        try {
            $response = $this->stores->for($file->disk)->download($file->path, $file->original_name);
        } catch (FileException|HttpExceptionInterface) {
            return $fallback;
        }

        if (! $response instanceof RedirectResponse) {
            return $fallback;
        }

        $url = $response->getTargetUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires = isset($query['expires']) && is_numeric($query['expires']) ? (int) $query['expires'] : null;

        return ['url' => $url, 'expires_at' => $expires === null ? null : date(DATE_ATOM, $expires)];
    }
}
