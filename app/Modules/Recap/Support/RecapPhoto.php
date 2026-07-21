<?php

declare(strict_types=1);

namespace App\Modules\Recap\Support;

/**
 * One gallery photo surfaced on the public event recap, linking to the
 * public (auth-free) thumbnail route — see {@see RecapProjection}.
 */
final readonly class RecapPhoto
{
    public function __construct(
        public string $url,
        public ?string $caption,
    ) {}

    /**
     * @return array{url: string, caption: ?string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'caption' => $this->caption,
        ];
    }
}
