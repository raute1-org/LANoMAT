<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Domain\ServerPreset;
use App\Modules\GameServers\Exceptions\GameServerException;

/**
 * Whether a server preset can be started right now: it must be enabled by the
 * orga, and everything it requires must exist. Secrets (Steam Web API key,
 * GSLT) live in `services.pelican.*`, never in the preset, so a preset that
 * needs one stays locked until the deployment configures it.
 */
final class PresetAvailability
{
    /**
     * Reasons the preset is locked, most fundamental first; empty = available.
     * Values: `disabled`, `requires_authkey`, `requires_gslt`, `requires_content`.
     *
     * @return list<string>
     */
    public static function reasons(ServerPreset $preset): array
    {
        $reasons = [];

        if (! $preset->enabled) {
            $reasons[] = 'disabled';
        }

        $needsAuthkey = in_array('authkey', $preset->requires, true) || $preset->config->contentType === 'workshop';

        if ($needsAuthkey && ! self::configured('steam_web_api_key')) {
            $reasons[] = 'requires_authkey';
        }

        if (in_array('gslt', $preset->requires, true) && ! self::configured('gslt')) {
            $reasons[] = 'requires_gslt';
        }

        if (in_array('content', $preset->requires, true) && ! self::hasContent($preset)) {
            $reasons[] = 'requires_content';
        }

        return $reasons;
    }

    public static function isAvailable(ServerPreset $preset): bool
    {
        return self::reasons($preset) === [];
    }

    /**
     * @throws GameServerException
     */
    public static function assertAvailable(ServerPreset $preset): void
    {
        $reasons = self::reasons($preset);

        if ($reasons !== []) {
            throw GameServerException::presetUnavailable($reasons[0]);
        }
    }

    /**
     * German explanation for the first reason, or null when available.
     */
    public static function explanation(ServerPreset $preset): ?string
    {
        $reasons = self::reasons($preset);

        if ($reasons === []) {
            return null;
        }

        $text = __("gameservers.errors.preset_unavailable_{$reasons[0]}");

        return is_string($text) ? $text : null;
    }

    private static function hasContent(ServerPreset $preset): bool
    {
        return in_array($preset->config->contentType, ['workshop', 'upload'], true) && $preset->config->contentRef !== null;
    }

    private static function configured(string $key): bool
    {
        $value = config("services.pelican.{$key}");

        return is_string($value) && $value !== '';
    }
}
