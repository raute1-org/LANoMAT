<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use finfo;
use Illuminate\Http\UploadedFile;

/**
 * Decides whether an upload is a type a browser could execute from our own
 * origin (HTML, SVG, JavaScript). Everything else is allowed on purpose: the
 * moderation gate plus attachment-only delivery (`nosniff`, opaque content
 * type) are what make executables safe to share, not a type allowlist.
 *
 * Never trusts the client alone: the extension of the client-supplied name
 * (normalised: lowercase, no trailing dots/spaces, which Windows ignores) AND
 * the MIME sniffed from the file's bytes AND the client-sent MIME are all
 * checked, and any one of them is enough to refuse.
 */
final class UploadTypeGuard
{
    /**
     * @param  list<string>  $blockedExtensions
     * @param  list<string>  $blockedMimes
     */
    public function __construct(
        private readonly array $blockedExtensions,
        private readonly array $blockedMimes,
    ) {}

    public static function fromConfig(): self
    {
        /** @var list<string> $extensions */
        $extensions = (array) config('files.blocked_extensions', []);
        /** @var list<string> $mimes */
        $mimes = (array) config('files.blocked_mimes', []);

        return new self($extensions, $mimes);
    }

    public function isBlocked(UploadedFile $file): bool
    {
        return $this->isBlockedType(
            $file->getClientOriginalName(),
            $this->sniffMime($file),
            $file->getClientMimeType(),
            $file->getMimeType(),
        );
    }

    public function isBlockedType(string $clientName, ?string $sniffedMime, ?string $clientMime, ?string $guessedMime = null): bool
    {
        if (in_array(self::extension($clientName), $this->blockedExtensions, true)) {
            return true;
        }

        foreach ([$sniffedMime, $clientMime, $guessedMime] as $mime) {
            if ($mime !== null && in_array(self::normalizeMime($mime), $this->blockedMimes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The MIME type read from the file content (finfo/libmagic), independent
     * of the name and of the Content-Type the client sent. Null when the file
     * cannot be inspected.
     */
    public function sniffMime(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            return null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return $mime === false ? null : self::normalizeMime($mime);
    }

    public static function extension(string $name): string
    {
        // Windows and some servers drop trailing dots/spaces ("x.html. " is x.html); NULs are never legitimate.
        $name = rtrim(str_replace("\0", '', $name), ". \t\r\n");

        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    private static function normalizeMime(string $mime): string
    {
        // Drop parameters ("text/html; charset=utf-8") and case.
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }
}
