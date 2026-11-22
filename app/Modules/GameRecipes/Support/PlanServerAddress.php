<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Facades\Gate;

/**
 * The two plan variables `{{server_host}}` and `{{server_port}}`: a recipe never
 * names this installation's server, the server fills both in when it builds the
 * plan (game-sharing plan section 8.2, decision G4).
 *
 * The value comes from a server of the recipe's game that this viewer may see
 * anyway: the servers behind `/servers` and `/servers/all`
 * ({@see ServerListProjection}). "The recipe's game" is strict here, stricter
 * than the install links of a server row: **the server's game must carry the
 * recipe's slug.** A name that merely resembles the recipe (the browser twin
 * of a native game, a sequel, a mod) never qualifies, because the value is
 * written into the game's configuration on a player's PC, and a wrong host
 * there is worse than none. A server that is switched off or still being set
 * up does not qualify either. Among the rest, the ranking is total:
 *
 *  1. a server that answers (Ready) before one that does not,
 *  2. a permanent server before an event or match server (it is the one that
 *     runs beyond this week),
 *  3. the lowest id.
 *
 * Both values land inside quoted shell arguments on a player's PC, so the host
 * keeps letters, digits, dot and hyphen only (at most 253 characters, not
 * trimmed: a stray space or line break means empty instead of guessed) and the
 * port must be 1..65535. Everything else — and "no such server" — is the empty
 * string, which makes the step leave the game's configuration alone. An IPv6
 * literal is therefore empty: the contract has no colons.
 *
 * GameServers is optional, so this class answers nothing while it is off.
 */
final class PlanServerAddress
{
    /** Placeholders a recipe may use instead of naming this installation's server. */
    public const string HOST = '{{server_host}}';

    public const string PORT = '{{server_port}}';

    /** Letters, digits, dot and hyphen only, at most 253: nothing a shell, YAML or Markdown could act on. */
    private const string HOST_PATTERN = '/^[A-Za-z0-9.-]{1,253}$/';

    public function __construct(
        private readonly Modules $modules,
    ) {}

    /**
     * The substitution table for the two placeholders, empty strings when the
     * plan names no server. Feed it to `strtr()` so substituted text is never
     * scanned again (a value that looks like another placeholder stays inert).
     *
     * @return array<string, string>
     */
    public function variables(GameRecipe $recipe, User $viewer, ?Event $event = null): array
    {
        $server = $this->server($recipe, $viewer, $event);

        return [
            self::HOST => self::host($server['address'] ?? null),
            self::PORT => self::port($server['port'] ?? null),
        ];
    }

    /**
     * Whether the recipe text uses either placeholder at all. A plan for a
     * recipe without them then costs no server query.
     */
    public function uses(GameRecipe $recipe): bool
    {
        foreach ((array) ($recipe->steps ?? []) as $step) {
            foreach ((array) data_get($step, 'commands', []) as $command) {
                if (self::names($command)) {
                    return true;
                }
            }

            foreach ((array) data_get($step, 'verify', []) as $check) {
                if (self::names($check)) {
                    return true;
                }
            }
        }

        foreach ($recipe->launchTargets() as $target) {
            if (self::names($target)) {
                return true;
            }
        }

        return self::names($recipe->launchTarget());
    }

    /**
     * The substitution table for one viewer, or an empty table when the recipe
     * uses neither placeholder (which then costs no server query). Every place
     * that hands a recipe's commands to a client asks here, so no client ever
     * receives a raw placeholder: the install plan, and the recipe detail an
     * agent reads its launch targets from.
     *
     * @return array<string, string>
     */
    public function variablesFor(GameRecipe $recipe, User $viewer, ?Event $event = null): array
    {
        return $this->uses($recipe) ? $this->variables($recipe, $viewer, $event) : [];
    }

    /**
     * Substitute the plan variables in one authored text. `strtr()` replaces in
     * one pass, so an inserted value is never scanned for placeholders again.
     *
     * @param  array<string, string>  $variables
     */
    public static function text(?string $text, array $variables): ?string
    {
        if ($text === null || $variables === []) {
            return $text;
        }

        return strtr($text, $variables);
    }

    /**
     * One `verify` entry (`file_exists`/`dir_exists` path, or `command` with
     * args) with the plan variables filled in.
     *
     * @param  array<string, mixed>  $check
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    public static function check(array $check, array $variables): array
    {
        foreach (['path', 'command'] as $key) {
            if (is_string($check[$key] ?? null)) {
                $check[$key] = self::text($check[$key], $variables);
            }
        }

        if (is_array($check['args'] ?? null)) {
            $check['args'] = array_map(
                static fn (mixed $arg): mixed => is_string($arg) ? self::text($arg, $variables) : $arg,
                $check['args'],
            );
        }

        return $check;
    }

    /**
     * One authored step as it is stored, with the variables filled in where a
     * client executes or checks text: its commands and its `verify` entries.
     * Titles and the `detail` prose stay as written.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    public static function step(array $step, array $variables): array
    {
        if ($variables === []) {
            return $step;
        }

        if (is_array($step['commands'] ?? null)) {
            $step['commands'] = array_map(static function (mixed $command) use ($variables): mixed {
                if (is_array($command) && is_string($command['run'] ?? null)) {
                    $command['run'] = self::text($command['run'], $variables);
                }

                return $command;
            }, $step['commands']);
        }

        if (is_array($step['verify'] ?? null)) {
            $step['verify'] = array_map(
                static fn (mixed $check): mixed => is_array($check) ? self::check($check, $variables) : $check,
                $step['verify'],
            );
        }

        return $step;
    }

    /**
     * Launch targets with the variables filled into their commands.
     *
     * @param  list<array<string, mixed>>  $targets
     * @param  array<string, string>  $variables
     * @return list<array<string, mixed>>
     */
    public static function launchTargets(array $targets, array $variables): array
    {
        if ($variables === []) {
            return $targets;
        }

        return array_map(static function (array $target) use ($variables): array {
            if (is_string($target['command'] ?? null)) {
                $target['command'] = self::text($target['command'], $variables);
            }

            return $target;
        }, $targets);
    }

    /** Whether one authored array (a command, a check, a launch target) carries a placeholder in any of its texts. */
    private static function names(mixed $entry): bool
    {
        if (is_array($entry)) {
            foreach ($entry as $value) {
                if (self::names($value)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($entry)
            && (str_contains($entry, self::HOST) || str_contains($entry, self::PORT));
    }

    /**
     * The server the plan may name, already reduced to the two values.
     *
     * @return array{address: string, port: int|null}|null
     */
    private function server(GameRecipe $recipe, User $viewer, ?Event $event): ?array
    {
        if (! $this->modules->enabled(Module::GameServers)) {
            return null;
        }

        $slug = mb_strtolower($recipe->slug);
        $candidates = [];

        foreach ($this->visibleLinks($viewer, $event) as $entry) {
            $link = $entry['link'];

            // Up or at least known to run; never one that is switched off,
            // failed or still being provisioned.
            if (! in_array($link->status, [ServerLinkStatus::Ready, ServerLinkStatus::NotResponding], true)) {
                continue;
            }

            if (mb_strtolower((string) ServerListProjection::gameOf($link)?->slug) !== $slug) {
                continue;
            }

            $address = $link->join_info->address;

            // A server without a usable host name cannot fill the placeholder,
            // so it is not a candidate at all.
            if (self::host($address) === '') {
                continue;
            }

            $candidates[] = [
                'ready' => $link->status === ServerLinkStatus::Ready,
                'permanent' => $entry['permanent'],
                'id' => $link->id,
                'address' => (string) $address,
                'port' => $link->join_info->port,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        // true sorts before false for the two flags, the lower id first.
        usort($candidates, static fn (array $a, array $b): int => [
            $b['ready'], $b['permanent'], $a['id'],
        ] <=> [
            $a['ready'], $a['permanent'], $b['id'],
        ]);

        return ['address' => $candidates[0]['address'], 'port' => $candidates[0]['port']];
    }

    /**
     * The servers this viewer may see anyway, in the scope of the plan, as
     * models (no row is built for them).
     *
     * With an event, exactly that event's list — the native client's stricter
     * view, where a match server is only named to the players of that match and
     * to orga — plus the permanent servers. Without one, what `/servers/all`
     * aggregates: the servers of every public, non-archived event plus the
     * permanent servers.
     *
     * @return list<array{link: ServerLink, permanent: bool}>
     */
    private function visibleLinks(User $viewer, ?Event $event): array
    {
        if (! Gate::forUser($viewer)->allows('viewPermanent', ServerLink::class)) {
            return [];
        }

        $links = [];

        if (! $event instanceof Event) {
            foreach (ServerListProjection::linksForGlobalBrowser() as $entry) {
                $links[] = ['link' => $entry['link'], 'permanent' => $entry['event'] === null];
            }

            return $links;
        }

        foreach (ServerListProjection::linksForEventViewedBy($event, $viewer) as $link) {
            $links[] = ['link' => $link, 'permanent' => false];
        }

        foreach (ServerListProjection::permanentLinks() as $link) {
            $links[] = ['link' => $link, 'permanent' => true];
        }

        return $links;
    }

    /**
     * The host exactly as it may be substituted, or the empty string. Not
     * trimmed on purpose: a row that carries a space or a line break is refused
     * instead of silently repaired.
     */
    private static function host(?string $value): string
    {
        if ($value === null || preg_match(self::HOST_PATTERN, $value) !== 1) {
            return '';
        }

        return $value;
    }

    /** The port as it may be substituted, or the empty string outside 1..65535. */
    private static function port(?int $value): string
    {
        return $value !== null && $value >= 1 && $value <= 65535 ? (string) $value : '';
    }
}
