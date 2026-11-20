<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support\Cs2;

use App\Modules\GameServers\Exceptions\GameServerException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Reads, validates and merges the two config files of the `ssypchenko/cs2-gungame`
 * plugin (`gungame.json` and `gungame_weapons.json` in `game/csgo/cfg/gungame/`).
 * Pure: no IO, the Actions fetch and write the files.
 *
 * The files are edited, never regenerated: they are decoded as objects (so `{}`
 * stays `{}` and numeric keys stay keys), only the keys of {@see FIELDS} and the two
 * weapon maps are touched, and every other key, `_comment.*` included, is written back
 * unchanged. A file that is not a JSON object is refused and never overwritten.
 */
final class GunGameConfig
{
    /** The preset key that marks a server as a GunGame server. */
    public const string PRESET_KEY = 'cs2-gungame';

    public const string GENERAL_FILE = 'game/csgo/cfg/gungame/gungame.json';

    public const string WEAPONS_FILE = 'game/csgo/cfg/gungame/gungame_weapons.json';

    /** @var list<string> */
    public const array WEAPONS = [
        'ak47', 'm4a1_silencer', 'm4a1', 'famas', 'galilar', 'aug', 'sg556', 'awp', 'ssg08', 'g3sg1', 'scar20',
        'mp9', 'mac10', 'mp7', 'mp5sd', 'ump45', 'p90', 'bizon', 'mag7', 'nova', 'xm1014', 'sawedoff', 'm249', 'negev',
        'deagle', 'elite', 'fiveseven', 'glock', 'hkp2000', 'usp_silencer', 'p250', 'tec9', 'cz75a', 'revolver',
        'hegrenade', 'knife',
    ];

    /**
     * The form fields: key => [kind, min, max]. A `flag` is a boolean (written as
     * `true`/`false`, or `1`/`0` when the file already uses numbers), `int` a bounded
     * integer, `weapon` a weapon name or empty.
     *
     * @var array<string, array{0: string, 1: int, 2: int}>
     */
    public const array FIELDS = [
        'MinKillsPerLevel' => ['int', 1, 20],
        'WarmupEnabled' => ['flag', 0, 1],
        'WarmupTimeLength' => ['int', 0, 600],
        'TurboMode' => ['flag', 0, 1],
        'KnifePro' => ['flag', 0, 1],
        'KnifeProMinLevel' => ['int', 1, 100],
        'FriendlyFireAllowed' => ['flag', 0, 1],
        'AutoFriendlyFire' => ['flag', 0, 1],
        'TkLooseLevel' => ['flag', 0, 1],
        'RespawnByPlugin' => ['int', 0, 5],
        'SpawnDistance' => ['int', 0, 10000],
        'HandicapMode' => ['int', 0, 10],
        'HandicapUpdate' => ['int', 0, 3600],
        'EndGameDelay' => ['int', 0, 120],
        'NadeBonusWeapon' => ['weapon', 0, 0],
    ];

    private const int MAX_LEVELS = 100;

    private const int MAX_KILLS_PER_LEVEL = 20;

    /**
     * @throws GameServerException
     */
    public static function decode(string $raw, string $path): stdClass
    {
        $doc = json_decode($raw, false, 512);

        if (! $doc instanceof stdClass) {
            throw GameServerException::configFileInvalid($path);
        }

        return $doc;
    }

    /**
     * The form state from the two decoded files.
     *
     * @return array<string, mixed>
     */
    public static function formState(stdClass $general, stdClass $weapons): array
    {
        $state = [];

        foreach (self::FIELDS as $key => [$kind]) {
            if (! property_exists($general, $key)) {
                $state[$key] = null;

                continue;
            }

            $value = $general->{$key};
            $state[$key] = match ($kind) {
                'flag' => (bool) $value ? 'on' : 'off',
                'int' => is_numeric($value) ? (int) $value : null,
                default => is_string($value) ? $value : null,
            };
        }

        $order = property_exists($weapons, 'WeaponOrder') && $weapons->WeaponOrder instanceof stdClass ? (array) $weapons->WeaponOrder : [];
        ksort($order, SORT_NUMERIC);
        $kills = property_exists($weapons, 'MultipleKillsPerLevel') && $weapons->MultipleKillsPerLevel instanceof stdClass ? (array) $weapons->MultipleKillsPerLevel : [];

        $levels = [];

        foreach ($order as $level => $weapon) {
            $levels[] = [
                'weapon' => is_string($weapon) ? $weapon : '',
                'kills' => isset($kills[(string) $level]) && is_numeric($kills[(string) $level]) ? (int) $kills[(string) $level] : null,
            ];
        }

        $state['weapon_order'] = $levels;

        return $state;
    }

    /**
     * Validates raw form input.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $rules = [
            'weapon_order' => ['required', 'array', 'min:1', 'max:'.self::MAX_LEVELS],
            'weapon_order.*.weapon' => ['required', Rule::in(self::WEAPONS)],
            'weapon_order.*.kills' => ['nullable', 'integer', 'between:1,'.self::MAX_KILLS_PER_LEVEL],
        ];
        $attributes = [];

        foreach (self::FIELDS as $key => [$kind, $min, $max]) {
            $rules[$key] = match ($kind) {
                'flag' => ['nullable', Rule::in(['on', 'off'])],
                'int' => ['nullable', 'integer', "between:{$min},{$max}"],
                default => ['nullable', Rule::in(self::WEAPONS)],
            };
            $attributes[$key] = __("gameservers.settings.gungame.fields.{$key}");
        }

        $input = array_map(static fn (mixed $v): mixed => $v === '' ? null : $v, $input);

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, $rules, attributes: $attributes + ['weapon_order' => __('gameservers.settings.gungame.weapon_order')])->validate();

        return $data;
    }

    /**
     * Merges validated input into the decoded general file. A blank value leaves
     * the key alone; a key that is not in the file is only added when a value is given.
     *
     * @param  array<string, mixed>  $data
     */
    public static function mergeGeneral(stdClass $general, array $data): stdClass
    {
        foreach (self::FIELDS as $key => [$kind]) {
            $value = $data[$key] ?? null;

            if ($value === null) {
                continue;
            }

            $general->{$key} = match ($kind) {
                'flag' => self::flag($general, $key, $value === 'on'),
                'int' => (int) $value,
                default => (string) $value,
            };
        }

        return $general;
    }

    /**
     * Replaces `WeaponOrder` (levels renumbered from 1) and `MultipleKillsPerLevel`
     * (level => kills, only for levels that have a value), leaving every other key.
     *
     * @param  array<string, mixed>  $data
     */
    public static function mergeWeapons(stdClass $weapons, array $data): stdClass
    {
        $order = [];
        $kills = [];

        /** @var list<array{weapon: string, kills: mixed}> $levels */
        $levels = array_values($data['weapon_order']);

        foreach ($levels as $i => $level) {
            $order[(string) ($i + 1)] = $level['weapon'];

            if (($level['kills'] ?? null) !== null) {
                $kills[(string) ($i + 1)] = (int) $level['kills'];
            }
        }

        $weapons->WeaponOrder = (object) $order;

        if ($kills !== [] || property_exists($weapons, 'MultipleKillsPerLevel')) {
            $weapons->MultipleKillsPerLevel = (object) $kills;
        }

        return $weapons;
    }

    /** Pretty JSON with two-space indentation, slashes and unicode unescaped, and a trailing newline. */
    public static function encode(stdClass $doc): string
    {
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        // JSON_PRETTY_PRINT indents with four spaces; JSON strings never contain a raw newline.
        $json = (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json);

        return $json."\n";
    }

    /** A flag keeps the file's own representation: numbers when the file used numbers. */
    private static function flag(stdClass $general, string $key, bool $on): bool|int
    {
        return property_exists($general, $key) && is_int($general->{$key}) ? (int) $on : $on;
    }
}
