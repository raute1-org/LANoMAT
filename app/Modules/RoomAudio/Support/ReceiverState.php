<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Support;

/**
 * Snapshot of the receiver as reported by the denon-admin sidecar's GET /state.
 * `mode` is a whitelist key ('' when the receiver reports a mode that is not
 * in `modes`); `modeName` is the raw reply for display.
 * Free text (source and mode names) is sanitised by the sidecar before it
 * arrives here; this class only checks the shape.
 */
final readonly class ReceiverState
{
    private const DEFAULT_VOLUME_MAX = 98.0;

    /**
     * @param  list<array{code: string, name: string}>  $sources
     * @param  list<array{key: string, name: string}>  $modes
     */
    public function __construct(
        public bool $connected,
        public bool $power,
        public ?float $volume,
        public float $volumeMax,
        public bool $mute,
        public string $source,
        public string $mode,
        public array $sources,
        public array $modes,
        public string $modeName = '',
    ) {}

    public static function unreachable(): self
    {
        return new self(
            connected: false,
            power: false,
            volume: null,
            volumeMax: self::DEFAULT_VOLUME_MAX,
            mute: false,
            source: '',
            mode: '',
            sources: [],
            modes: [],
            modeName: '',
        );
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public static function fromArray(array $d): self
    {
        $volume = $d['volume'] ?? null;
        $volumeMax = $d['volumeMax'] ?? null;

        return new self(
            connected: (bool) ($d['connected'] ?? false),
            power: (bool) ($d['power'] ?? false),
            volume: is_numeric($volume) ? (float) $volume : null,
            volumeMax: is_numeric($volumeMax) ? (float) $volumeMax : self::DEFAULT_VOLUME_MAX,
            mute: (bool) ($d['mute'] ?? false),
            source: is_string($d['source'] ?? null) ? $d['source'] : '',
            mode: is_string($d['mode'] ?? null) ? $d['mode'] : '',
            sources: self::sources($d['sources'] ?? null),
            modes: self::modes($d['modes'] ?? null),
            modeName: is_string($d['modeName'] ?? null) ? $d['modeName'] : '',
        );
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private static function sources(mixed $items): array
    {
        $sources = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item['code'] ?? null) && is_string($item['name'] ?? null)) {
                $sources[] = ['code' => $item['code'], 'name' => $item['name']];
            }
        }

        return $sources;
    }

    /**
     * @return list<array{key: string, name: string}>
     */
    private static function modes(mixed $items): array
    {
        $modes = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item['key'] ?? null) && is_string($item['name'] ?? null)) {
                $modes[] = ['key' => $item['key'], 'name' => $item['name']];
            }
        }

        return $modes;
    }
}
