<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/**
 * The file name stored in `shared_files.original_name` and
 * `upload_reservations.original_name`, and therefore the one every surface
 * shows: the files page, a download's `Content-Disposition`, and the recipe
 * text of a shared game (name, glob, quoted shell literal).
 *
 * Both upload paths clean with this one function — the resumable ticket
 * (`IssueUploadTicket`) and the multipart form (`UploadSharedFile`) — so a row
 * never depends on which way its bytes arrived.
 *
 * It removes only what can never be part of a stored name: control characters
 * (a line break would end a YAML front matter and a table cell), everything up
 * to the last path separator (a path is never shown or used as a download
 * name) and anything past 255 characters. Everything else is left alone:
 * a backtick, a glob metacharacter or a leading dot survives, because a name
 * that a shared game cannot quote is refused by PortableRecipeBuilder, not
 * silently rewritten here.
 */
final class UploadFileName
{
    /** The longest name `shared_files.original_name` may hold. */
    public const MAX_LENGTH = 255;

    public static function clean(string $name): string
    {
        // Control characters (C0, DEL, C1), the line and paragraph separators,
        // and the invisible formatting characters that make a name read as
        // something it is not: zero-width characters, the byte order mark and
        // the bidirectional overrides ("gnp.exe" shown as "exe.png").
        // Invalid UTF-8 makes the /u pattern return null, which cleans to ''.
        $name = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $name);
        $name = trim(str_replace('\\', '/', $name));

        return mb_substr(basename($name), 0, self::MAX_LENGTH);
    }

    /**
     * The name a download is offered under. Rows stored before both upload
     * paths cleaned their names can still hold a path separator or a control
     * character, which a `Content-Disposition` header refuses; they are
     * cleaned on the way out, and a name that cleans to nothing becomes "file".
     */
    public static function forDownload(string $name): string
    {
        $clean = self::clean($name);

        return $clean !== '' ? $clean : 'file';
    }
}
