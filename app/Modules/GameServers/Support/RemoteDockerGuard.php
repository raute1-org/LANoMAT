<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Exceptions\GameServerException;

/**
 * Input validation and output hygiene for the remote Docker control. Anything that reaches
 * a shell command line passes here first, and anything that comes back is cleaned before
 * it is stored, logged or shown.
 */
final class RemoteDockerGuard
{
    public const CONTAINER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D';

    public const MAX_COMMAND_LENGTH = 256;

    public const MIN_LINES = 1;

    public const MAX_LINES = 500;

    /** Response cap in bytes. */
    public const MAX_OUTPUT_BYTES = 65536;

    /**
     * @throws GameServerException
     */
    public static function container(string $name): string
    {
        // The `D` modifier makes `$` match only at the very end, so "name\n" is refused too.
        if (preg_match(self::CONTAINER_PATTERN, $name) !== 1) {
            throw GameServerException::remoteInvalid('container');
        }

        return $name;
    }

    /**
     * A console command: one line of printable ASCII, 1 to 256 long, not starting with `-`
     * (the console tool would read it as a flag). Returned trimmed, never rewritten.
     * ASCII only on purpose: `escapeshellarg` drops bytes it cannot place in the current
     * locale, which would silently change the command.
     *
     * @throws GameServerException
     */
    public static function command(string $command): string
    {
        $command = trim($command, ' ');

        // The class excludes every control character (NUL, CR, LF, tab, ESC ...) and all bytes above 0x7E.
        if ($command === ''
            || strlen($command) > self::MAX_COMMAND_LENGTH
            || preg_match('/^[\x20-\x7E]+$/D', $command) !== 1
            || str_starts_with($command, '-')) {
            throw GameServerException::remoteInvalid('command');
        }

        return $command;
    }

    /**
     * @throws GameServerException
     */
    public static function lines(int $lines): int
    {
        if ($lines < self::MIN_LINES || $lines > self::MAX_LINES) {
            throw GameServerException::remoteInvalid('lines');
        }

        return $lines;
    }

    /** First 80 characters of a command, for the audit log. */
    public static function auditSnippet(string $command): string
    {
        return mb_substr((string) preg_replace('/\p{C}+/u', ' ', $command), 0, 80, 'UTF-8');
    }

    /**
     * Makes remote output safe to show: valid UTF-8, no ANSI/terminal escapes, no Minecraft
     * colour codes, no control characters except newline and tab, and only the last
     * {@see self::MAX_OUTPUT_BYTES} bytes (the tail is what a log reader wants).
     */
    public static function sanitizeOutput(string $output): string
    {
        $output = mb_scrub($output, 'UTF-8');
        // OSC sequences (title etc.), CSI sequences, and any remaining two-byte escape.
        $output = (string) preg_replace([
            '/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/u',
            '/\x1B\[[0-?]*[ -\/]*[@-~]/u',
            '/\x1B./us',
            '/§[0-9a-fk-or]/iu',
        ], '', $output);
        $output = str_replace("\r\n", "\n", $output);
        $output = (string) preg_replace('/[^\P{C}\n\t]/u', '', $output);

        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            $output = mb_strcut($output, strlen($output) - self::MAX_OUTPUT_BYTES, null, 'UTF-8');
        }

        return trim($output, "\n");
    }
}
