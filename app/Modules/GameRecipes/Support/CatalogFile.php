<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use DomainException;

/**
 * The catalogue index (`_catalog.md`) as a value: one row per recipe, grouped
 * under the heading of its status and sorted by slug. The repository's
 * validator treats the table as the human index of the directory — a recipe
 * that is missing, listed twice or under the wrong status is an error — so a
 * pull request has to keep it in order rather than append a line.
 *
 * No IO: the action passes the text it read from the base branch and gets the
 * text to send back.
 */
final class CatalogFile
{
    /** The status headings the validator recognises, in its order. */
    private const array STATUS_PATTERNS = [
        'archived' => '/\barchived\b/i',
        'published' => '/\bpublished\b/i',
        'draft' => '/\bdrafts?\b/i',
    ];

    /**
     * Put `$row` into the table under the heading for `$status`, in slug order,
     * replacing any existing row of that slug anywhere in the file. Every other
     * line stays byte-identical.
     *
     * @throws DomainException when the status is unknown or its heading/table is missing
     */
    public function withRow(string $catalog, string $slug, string $status, string $row): string
    {
        $status = mb_strtolower(trim($status));
        $pattern = self::STATUS_PATTERNS[$status] ?? null;

        if ($pattern === null) {
            throw new DomainException("Unknown catalogue status [{$status}].");
        }

        /** @var list<string> $lines each entry keeps its own line ending */
        $lines = preg_split('/(?<=\n)/', $catalog) ?: [];

        [$heading, $header, $separator] = $this->table($lines, $pattern, $status);

        $remove = [];

        foreach ($lines as $index => $line) {
            if ($this->rowSlug($line) === $slug) {
                $remove[$index] = true;
            }
        }

        // Where the new row belongs: before the first row whose slug sorts
        // after it, else after the last data row of the table.
        $insertBefore = null;
        $lastRow = $separator;

        for ($index = $heading + 1; $index < count($lines); $index++) {
            if ($this->isHeading($lines[$index])) {
                break;
            }

            if ($index <= $separator || isset($remove[$index])) {
                continue;
            }

            $other = $this->rowSlug($lines[$index]);

            if ($other === null) {
                continue;
            }

            $lastRow = $index;

            if ($insertBefore === null && strnatcasecmp($other, $slug) > 0) {
                $insertBefore = $index;
            }
        }

        $eol = $this->eol($lines[$separator] ?? $lines[$header] ?? '');
        $newRow = rtrim($row, "\r\n").$eol;

        $out = [];
        $inserted = false;

        foreach ($lines as $index => $line) {
            if (isset($remove[$index])) {
                continue;
            }

            if (! $inserted && $index === $insertBefore) {
                $out[] = $newRow;
                $inserted = true;
            }

            $out[] = $line;

            if (! $inserted && $insertBefore === null && $index === $lastRow) {
                $out[] = $newRow;
                $inserted = true;
            }
        }

        if (! $inserted) {
            // The table ended at the file's last line: append with a fresh row.
            $out[] = $newRow;
        }

        return implode('', $out);
    }

    /**
     * The heading index and the header/separator lines of its first table.
     *
     * @param  list<string>  $lines
     * @return array{0: int, 1: int, 2: int}
     */
    private function table(array $lines, string $pattern, string $status): array
    {
        $heading = null;

        foreach ($lines as $index => $line) {
            $text = $this->content($line);

            if (preg_match('/^##\s+(.+?)\s*#*\s*$/', $text, $match) !== 1) {
                continue;
            }

            if (preg_match($pattern, $match[1]) === 1) {
                $heading = $index;
                break;
            }
        }

        if ($heading === null) {
            throw new DomainException("The catalogue has no {$status} section.");
        }

        for ($index = $heading + 1; $index < count($lines); $index++) {
            if ($this->isHeading($lines[$index])) {
                break;
            }

            if (! $this->isTableLine($lines[$index])) {
                continue;
            }

            $separator = $index + 1;

            if ($separator < count($lines) && $this->isSeparator($lines[$separator])) {
                return [$heading, $index, $separator];
            }
        }

        throw new DomainException("The catalogue's {$status} section has no table.");
    }

    /** The slug in the first cell of a table row, if the row is a recipe row. */
    private function rowSlug(string $line): ?string
    {
        if (! $this->isTableLine($line)) {
            return null;
        }

        $cells = $this->cells($line);
        $first = trim($cells[0] ?? '');

        return preg_match('/^`([^`]+)`$/', $first, $match) === 1 ? $match[1] : null;
    }

    private function isHeading(string $line): bool
    {
        return preg_match('/^#{1,6}\s+/', $this->content($line)) === 1;
    }

    private function isTableLine(string $line): bool
    {
        return str_starts_with(trim($this->content($line)), '|');
    }

    private function isSeparator(string $line): bool
    {
        if (! $this->isTableLine($line)) {
            return false;
        }

        $cells = $this->cells($line);

        return $cells !== [] && array_all(
            $cells,
            static fn (string $cell): bool => preg_match('/^:?-{3,}:?$/', $cell) === 1,
        );
    }

    /**
     * @return list<string>
     */
    private function cells(string $line): array
    {
        $trimmed = trim(trim($this->content($line)), '|');

        return array_map(trim(...), explode('|', $trimmed));
    }

    private function content(string $line): string
    {
        return rtrim($line, "\r\n");
    }

    private function eol(string $line): string
    {
        return str_ends_with($line, "\r\n") ? "\r\n" : "\n";
    }
}
