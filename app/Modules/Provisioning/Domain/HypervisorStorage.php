<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

final readonly class HypervisorStorage
{
    /**
     * @param  list<string>  $content  e.g. images, rootdir, iso, vztmpl, backup, import
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $content,
        public bool $active,
        public bool $enabled,
        public bool $shared,
        public int $total,
        public int $avail,
        public int $used,
    ) {}

    public function acceptsContent(string $type): bool
    {
        return in_array($type, $this->content, true);
    }

    public function usable(): bool
    {
        return $this->active && $this->enabled;
    }
}
