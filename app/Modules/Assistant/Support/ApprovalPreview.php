<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Support\SafeText;

/**
 * What an approval card shows. Built only from the tool call's stored
 * arguments (never from model prose) by the tool's own `preview()`, so the
 * card cannot say something different from what will run.
 */
final readonly class ApprovalPreview
{
    /** @param list<array{label: string, value: string, mono: bool}> $rows */
    public function __construct(
        public string $title,
        public array $rows = [],
        public bool $danger = false,
        public bool $needsSecret = false,
    ) {}

    /**
     * Row builder: labels and values are bounded single lines (values that
     * originate from users, such as names, may carry newlines or markup).
     *
     * @return array{label: string, value: string, mono: bool}
     */
    public static function row(string $label, string|int|float|bool|null $value, bool $mono = false): array
    {
        return [
            'label' => $label,
            'value' => SafeText::line(is_bool($value) ? ($value ? 'ja' : 'nein') : (string) $value, 500) ?? '—',
            'mono' => $mono,
        ];
    }

    /** @return array{title: string, rows: list<array{label: string, value: string, mono: bool}>, danger: bool, needsSecret: bool} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'rows' => $this->rows, 'danger' => $this->danger, 'needsSecret' => $this->needsSecret];
    }
}
