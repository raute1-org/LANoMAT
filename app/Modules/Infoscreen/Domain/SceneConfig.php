<?php

namespace App\Modules\Infoscreen\Domain;

/**
 * The per-scene configuration bag for an InfoscreenScene.
 *
 * This is deliberately one flat, permissive DTO covering the union of all
 * scene-type option keys, rather than a per-SceneType class hierarchy: each
 * scene type only needs at most a couple of fields (see roadmap YAGNI note),
 * so a hierarchy would add indirection without real benefit. Each scene
 * component simply reads the keys it cares about. See SceneConfigCast for
 * the DB jsonb <-> SceneConfig bridge.
 */
final readonly class SceneConfig
{
    /**
     * @param  list<string>  $sponsorLogoPaths
     */
    public function __construct(
        public ?int $tournamentId = null,
        public ?string $headline = null,
        public ?string $body = null,
        public ?string $qrPayload = null,
        public ?string $qrCaption = null,
        public array $sponsorLogoPaths = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'tournament_id' => $this->tournamentId,
            'headline' => $this->headline,
            'body' => $this->body,
            'qr_payload' => $this->qrPayload,
            'qr_caption' => $this->qrCaption,
            'sponsor_logo_paths' => $this->sponsorLogoPaths,
        ];

        return array_filter(
            $data,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tournamentId = $data['tournament_id'] ?? null;
        $sponsorLogoPaths = $data['sponsor_logo_paths'] ?? [];

        return new self(
            tournamentId: $tournamentId !== null ? (int) $tournamentId : null,
            headline: $data['headline'] ?? null,
            body: $data['body'] ?? null,
            qrPayload: $data['qr_payload'] ?? null,
            qrCaption: $data['qr_caption'] ?? null,
            sponsorLogoPaths: is_array($sponsorLogoPaths) ? array_values($sponsorLogoPaths) : [],
        );
    }
}
