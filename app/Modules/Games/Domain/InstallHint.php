<?php

declare(strict_types=1);

namespace App\Modules\Games\Domain;

/**
 * "So kommst du ran" — how a participant gets a game installed before/at the
 * LAN: a `steam://` deeplink, a share link into the Files service (T5), and/or
 * a free-text version/modpack note. Mirrors ServerConfig's typed-jsonb
 * value-object shape (see roadmap insight #9 / ServerConfigCast): never a
 * loose string=>string map, so it always decodes to a well-defined shape
 * rather than an arbitrary array.
 */
final readonly class InstallHint
{
    public function __construct(
        public ?string $steamUrl = null,
        public ?string $shareUrl = null,
        public ?string $versionNote = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [
            'steam_url' => $this->steamUrl,
            'share_url' => $this->shareUrl,
            'version_note' => $this->versionNote,
        ];

        return array_filter(
            $data,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            steamUrl: $data['steam_url'] ?? null,
            shareUrl: $data['share_url'] ?? null,
            versionNote: $data['version_note'] ?? null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }
}
