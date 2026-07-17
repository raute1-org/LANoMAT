<?php

declare(strict_types=1);

namespace App\Modules\Voice\Domain;

enum VoiceProvider: string
{
    case Mumble = 'mumble';
    case TeamSpeak = 'teamspeak';

    public function label(): string
    {
        return match ($this) {
            self::Mumble => 'Mumble',
            self::TeamSpeak => 'TeamSpeak',
        };
    }

    /**
     * The providers enabled for this installation, in config order.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        /** @var array<int, string> $configured */
        $configured = config('services.voice.providers', ['mumble']);

        return array_values(array_filter(
            array_map(static fn (string $value): ?self => self::tryFrom($value), $configured),
        ));
    }
}
