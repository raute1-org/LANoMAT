<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support\Cs2;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The general settings of a CS2 server as LANoMAT manages them, and their mapping
 * onto the Pelican egg's startup variables and the in-game console. Pure: no IO.
 *
 * Every value is validated here (allowlists and formats), so the Filament form is
 * only a convenience: {@see validate()} is what the Actions call, and what it
 * returns is the only thing that ever reaches a Pelican startup variable or a
 * console command. Nothing in a console command is free text: a standard map is
 * one of {@see MAPS}, a workshop map is digits only.
 */
final readonly class Cs2Settings
{
    /** Startup variables of the CS2 egg that carry these settings. */
    public const string VAR_NAME = 'SERVER_NAME';

    public const string VAR_MAP = 'SRCDS_MAP';

    public const string VAR_TYPE = 'GAME_TYPE';

    public const string VAR_MODE = 'GAME_MODE';

    public const string VAR_PLAYERS = 'MAX_PLAYERS';

    public const string VAR_PASSWORD = 'SERVER_PASSWORD';

    /** Only set when the egg declares it: where a workshop map survives a restart. */
    public const string VAR_WORKSHOP = 'HOST_WORKSHOP_MAP';

    /** The variables whose absence from the egg is reported to the user. */
    public const array CORE_VARIABLES = [self::VAR_NAME, self::VAR_MAP, self::VAR_TYPE, self::VAR_MODE, self::VAR_PLAYERS, self::VAR_PASSWORD];

    /**
     * Game modes as `[game_type, game_mode]`.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const array MODES = [
        'casual' => [0, 0],
        'competitive' => [0, 1],
        'wingman' => [0, 2],
        'armsrace' => [1, 0],
        'demolition' => [1, 1],
        'deathmatch' => [1, 2],
    ];

    /**
     * Standard maps of the official modes, the allowlist for a non-workshop map.
     *
     * @var list<string>
     */
    public const array MAPS = [
        'de_ancient', 'de_anubis', 'de_dust2', 'de_inferno', 'de_mirage', 'de_nuke', 'de_overpass', 'de_train', 'de_vertigo',
        'de_brewery', 'de_dogtown', 'de_lake', 'de_office', 'de_italy',
        'ar_baggage', 'ar_shoots', 'ar_pool_day',
    ];

    public function __construct(
        public string $serverName,
        public string $mode,
        public ?string $map,
        public ?string $workshopId,
        public int $maxPlayers,
        public ?string $password,
        public bool $clearPassword,
    ) {}

    /**
     * Validates raw form input. A blank password means "unchanged", `clear_password` removes it.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $input = array_map(static fn (mixed $v): mixed => $v === '' ? null : $v, $input);

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, [
            'server_name' => ['required', 'string', 'max:64', 'regex:/^[\p{L}\p{N} _.,:+#()\[\]!&\/-]+$/u'],
            'mode' => ['required', Rule::in(array_keys(self::MODES))],
            'map' => ['nullable', 'required_without:workshop_id', Rule::in(self::MAPS)],
            'workshop_id' => ['nullable', 'required_without:map', 'regex:/^\d{6,12}$/'],
            'max_players' => ['required', 'integer', 'between:1,64'],
            'password' => ['nullable', 'string', 'max:30', 'alpha_dash:ascii'],
            'clear_password' => ['nullable', 'boolean'],
        ], attributes: [
            'server_name' => __('gameservers.settings.fields.server_name'),
            'mode' => __('gameservers.settings.fields.mode'),
            'map' => __('gameservers.settings.fields.map'),
            'workshop_id' => __('gameservers.settings.fields.workshop_id'),
            'max_players' => __('gameservers.settings.fields.max_players'),
            'password' => __('gameservers.settings.fields.password'),
        ])->validate();

        return new self(
            serverName: (string) $data['server_name'],
            mode: (string) $data['mode'],
            // A workshop id wins over a standard map when both are given.
            map: isset($data['workshop_id']) ? null : (isset($data['map']) ? (string) $data['map'] : null),
            workshopId: isset($data['workshop_id']) ? (string) $data['workshop_id'] : null,
            maxPlayers: (int) $data['max_players'],
            password: isset($data['password']) ? (string) $data['password'] : null,
            clearPassword: (bool) ($data['clear_password'] ?? false),
        );
    }

    /**
     * The startup variables to write. The password is left out when unchanged; a
     * workshop map leaves `SRCDS_MAP` alone (the fallback map stays) and goes to
     * `HOST_WORKSHOP_MAP` instead, which a standard map clears again.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        [$type, $mode] = self::MODES[$this->mode];

        $variables = [
            self::VAR_NAME => $this->serverName,
            self::VAR_TYPE => (string) $type,
            self::VAR_MODE => (string) $mode,
            self::VAR_PLAYERS => (string) $this->maxPlayers,
            self::VAR_WORKSHOP => $this->workshopId ?? '',
        ];

        if ($this->map !== null) {
            $variables[self::VAR_MAP] = $this->map;
        }

        if ($this->clearPassword) {
            $variables[self::VAR_PASSWORD] = '';
        } elseif ($this->password !== null) {
            $variables[self::VAR_PASSWORD] = $this->password;
        }

        return $variables;
    }

    /** The new password for the join info, or null when it stays as it is. */
    public function passwordChange(): ?string
    {
        return $this->clearPassword ? '' : $this->password;
    }

    /** The console command that changes the map on a running server. */
    public static function mapCommand(?string $map, ?string $workshopId): string
    {
        if ($workshopId !== null) {
            return "host_workshop_map {$workshopId}";
        }

        return "changelevel {$map}";
    }

    /**
     * The form state for the current startup environment (never the password).
     *
     * @param  array<string, mixed>  $environment
     * @return array{server_name: string, mode: string, map: string|null, workshop_id: string|null, max_players: int}
     */
    public static function formState(array $environment): array
    {
        $type = (int) ($environment[self::VAR_TYPE] ?? 0);
        $mode = (int) ($environment[self::VAR_MODE] ?? 1);
        $key = array_search([$type, $mode], self::MODES, true);
        $map = (string) ($environment[self::VAR_MAP] ?? '');
        $workshop = (string) ($environment[self::VAR_WORKSHOP] ?? '');

        return [
            'server_name' => (string) ($environment[self::VAR_NAME] ?? ''),
            'mode' => is_string($key) ? $key : 'competitive',
            'map' => in_array($map, self::MAPS, true) ? $map : null,
            'workshop_id' => preg_match('/^\d{6,12}$/', $workshop) === 1 ? $workshop : null,
            'max_players' => max(1, min(64, (int) ($environment[self::VAR_PLAYERS] ?? 10))),
        ];
    }
}
