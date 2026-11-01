<?php

declare(strict_types=1);

namespace App\Modules\Presence\Domain;

/**
 * What a person is playing. `source` is `steam` (their Steam status) or `query` (their name was
 * in the player list of one of LANoMAT's servers). When LANoMAT's own server is known (`linkId`)
 * the server title and address are the link's, which carry the exact join info.
 */
final readonly class GameLocation
{
    public const string SOURCE_STEAM = 'steam';

    public const string SOURCE_QUERY = 'query';

    public function __construct(
        public string $source,
        public ?string $game,
        public ?string $serverTitle,
        public ?string $address,
        public ?int $port,
        public ?int $linkId,
        public string $since,
    ) {}

    public function key(): string
    {
        return $this->linkId !== null
            ? 'link|'.$this->linkId
            : 'free|'.($this->game ?? '').'|'.($this->address ?? '').'|'.($this->port ?? '');
    }

    /**
     * @return array{source: string, game: ?string, serverTitle: ?string, address: ?string, port: ?int, linkId: ?int, since: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'game' => $this->game,
            'serverTitle' => $this->serverTitle,
            'address' => $this->address,
            'port' => $this->port,
            'linkId' => $this->linkId,
            'since' => $this->since,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: (string) ($data['source'] ?? self::SOURCE_STEAM),
            game: isset($data['game']) ? (string) $data['game'] : null,
            serverTitle: isset($data['serverTitle']) ? (string) $data['serverTitle'] : null,
            address: isset($data['address']) ? (string) $data['address'] : null,
            port: isset($data['port']) ? (int) $data['port'] : null,
            linkId: isset($data['linkId']) ? (int) $data['linkId'] : null,
            since: (string) ($data['since'] ?? ''),
        );
    }

    public function withSince(string $since): self
    {
        return new self($this->source, $this->game, $this->serverTitle, $this->address, $this->port, $this->linkId, $since);
    }
}
