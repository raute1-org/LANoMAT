<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Models;

use App\Models\User;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use Database\Factories\GameRecipeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One installable game recipe. `requirements`, `steps` and `metadata` keep the
 * authored YAML shape (validated by RecipeValidator) so the agent receives
 * exactly what a human wrote; `skill_markdown` is the ready-to-write
 * SKILL.md. Rows are upserted by slug from a recipe source.
 *
 * @property string $slug
 * @property string $title
 * @property list<string>|null $aliases
 * @property string|null $version
 * @property RecipeStatus $status
 * @property string $source
 * @property string|null $source_ref
 * @property list<string>|null $platforms
 * @property string|null $summary
 * @property string|null $description
 * @property string|null $homepage
 * @property string|null $license
 * @property string|null $skill_markdown
 * @property list<array<string, mixed>>|null $requirements
 * @property list<array<string, mixed>>|null $steps
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $reviewed_at
 * @property bool $in_lineup
 * @property int|null $contributor_user_id
 * @property int|null $pr_number
 * @property string|null $pr_url
 * @property string|null $pr_branch
 * @property PullRequestState|null $pr_state
 * @property string|null $pr_error
 * @property Carbon|null $pr_synced_at
 */
class GameRecipe extends Model
{
    /** @use HasFactory<GameRecipeFactory> */
    use HasFactory;

    /** Recipes authored in the admin panel (as opposed to a synced source kind). */
    public const SOURCE_PANEL = 'panel';

    /** Recipes a participant shared or contributed (web share page, MCP tool, REST). */
    public const SOURCE_CONTRIBUTION = 'contribution';

    // The pr_* columns are deliberately not fillable: only the proposal job and
    // the pull request status sync write them (forceFill).
    protected $fillable = [
        'slug',
        'title',
        'aliases',
        'version',
        'status',
        'in_lineup',
        'source',
        'source_ref',
        'platforms',
        'summary',
        'description',
        'homepage',
        'license',
        'cover_image_path',
        'skill_markdown',
        'requirements',
        'steps',
        'metadata',
        'contributor_user_id',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'platforms' => 'array',
            'requirements' => 'array',
            'steps' => 'array',
            'metadata' => 'array',
            'status' => RecipeStatus::class,
            'reviewed_at' => 'datetime',
            'in_lineup' => 'boolean',
            'pr_number' => 'integer',
            'pr_state' => PullRequestState::class,
            'pr_synced_at' => 'datetime',
        ];
    }

    /** Whether a participant shared or contributed this recipe (as opposed to the orga or a synced source). */
    public function isContribution(): bool
    {
        return $this->contributor_user_id !== null;
    }

    /**
     * The pull request that carries this recipe into the git catalogue, for a
     * page or an API answer. Null while nothing was proposed.
     *
     * @return array{number: int|null, url: string|null, state: string, label: string}|null
     */
    public function pullRequest(): ?array
    {
        if (! $this->pr_state instanceof PullRequestState) {
            return null;
        }

        return [
            'number' => $this->pr_number,
            'url' => $this->pr_state->existsRemotely() ? $this->pr_url : null,
            'state' => $this->pr_state->value,
            'label' => $this->pr_state->label(),
        ];
    }

    /** @return HasMany<GameRecipeFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(GameRecipeFile::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<GameRecipeInstall, $this> */
    public function installs(): HasMany
    {
        return $this->hasMany(GameRecipeInstall::class);
    }

    /** @return BelongsTo<User, $this> */
    public function contributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contributor_user_id');
    }

    /** @param Builder<GameRecipe> $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereIn('status', [RecipeStatus::Published->value]);
    }

    /** @param Builder<GameRecipe> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isOrga()) {
            $query->where(function ($visible) use ($user): void {
                $visible->whereIn('status', [RecipeStatus::Published->value])
                    ->orWhere(function ($own) use ($user): void {
                        $own->where('status', RecipeStatus::Draft->value)
                            ->where('contributor_user_id', $user->id);
                    });
            });
        }
    }

    public function isPublic(): bool
    {
        return $this->status->isPublic();
    }

    /** The aliases plus the slug and title, lowercased, for phrase matching. */
    /** @return list<string> */
    public function searchTerms(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $term): string => mb_strtolower(trim($term)),
            [$this->slug, $this->title, ...($this->aliases ?? [])],
        ))));
    }

    /**
     * Ways to start the installed game, most useful first.
     *
     * `metadata.launch_targets` wins when present (flat, vr, …); otherwise one
     * target is derived from `metadata.launch`, which may be a plain command
     * string or an object `{kind, target, args?, shell?}`. The legacy
     * `metadata.launch_vr` still contributes a `vr` entry when the list has none.
     *
     * @return list<array{id: string, label: string, os: string, command: string, shell: string|null, notes: string|null}>
     */
    public function launchTargets(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $targets = [];

        foreach ((array) ($metadata['launch_targets'] ?? []) as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            // A declared entry may carry the finished `command` or the
            // `{kind, target, args}` shape the `launch` object uses.
            $command = $entry['command'] ?? $this->launchCommandFrom($entry);

            if (! is_string($command) || trim($command) === '') {
                continue;
            }

            $id = $entry['id'] ?? null;
            $label = $entry['label'] ?? null;
            $os = $entry['os'] ?? null;
            $shell = $entry['shell'] ?? null;
            $notes = $entry['notes'] ?? null;

            $targets[] = [
                'id' => is_string($id) && $id !== '' ? $id : 'target-'.($index + 1),
                'label' => is_string($label) && $label !== '' ? $label : 'Start',
                'os' => is_string($os) && $os !== '' ? $os : 'any',
                'command' => $command,
                'shell' => is_string($shell) && $shell !== '' ? $shell : null,
                'notes' => is_string($notes) && $notes !== '' ? $notes : null,
            ];
        }

        if ($targets === []) {
            $command = $this->launchCommandFrom($metadata['launch'] ?? null);

            if ($command !== null) {
                $targets[] = [
                    'id' => 'default',
                    'label' => 'Start',
                    'os' => 'any',
                    'command' => $command,
                    'shell' => null,
                    'notes' => null,
                ];
            }
        }

        $vr = $metadata['launch_vr'] ?? null;

        if (is_string($vr) && trim($vr) !== '' && ! in_array('vr', array_column($targets, 'id'), true)) {
            $targets[] = [
                'id' => 'vr',
                'label' => 'VR',
                'os' => 'any',
                'command' => $vr,
                'shell' => null,
                'notes' => null,
            ];
        }

        return $targets;
    }

    /**
     * The command for one launch target. Precedence: an explicitly requested
     * target id, then the first target declared for the requested OS, then the
     * first (recommended) target. An unknown id falls back to that default
     * instead of leaving the game unlaunchable.
     */
    public function launchTarget(?string $id = null, ?string $os = null): ?string
    {
        $targets = $this->launchTargets();

        if ($id !== null) {
            foreach ($targets as $target) {
                if ($target['id'] === $id) {
                    return $target['command'];
                }
            }
        }

        if ($os !== null) {
            foreach ($targets as $target) {
                if ($target['os'] === $os) {
                    return $target['command'];
                }
            }
        }

        return $targets[0]['command'] ?? null;
    }

    /** Flatten the string or `{kind, target, args}` form into one command. */
    private function launchCommandFrom(mixed $launch): ?string
    {
        if (is_string($launch)) {
            return trim($launch) === '' ? null : trim($launch);
        }

        if (! is_array($launch)) {
            return null;
        }

        $target = $launch['target'] ?? $launch['command'] ?? null;

        if (! is_string($target) || trim($target) === '') {
            return null;
        }

        $args = $launch['args'] ?? null;
        $argLine = is_array($args)
            ? implode(' ', array_map(static fn (mixed $arg): string => (string) $arg, $args))
            : (is_string($args) ? $args : '');

        return match ($launch['kind'] ?? null) {
            // Steam takes the app id, not a path: steam://rungameid/<appid>//<args>.
            // A fully formed steam URI is already usable and stays untouched.
            'steam' => str_starts_with(trim($target), 'steam://')
                ? trim($target)
                : 'steam://rungameid/'.trim($target).($argLine !== '' ? '//'.$argLine : ''),
            'uri' => trim($target),
            default => trim($target.($argLine !== '' ? ' '.$argLine : '')),
        };
    }

    /**
     * Optional web-playable URL (e.g. the Ridge Racer cabinet). With a host,
     * the `{{host}}` template is replaced, so a recipe never hardcodes
     * `localhost` and the page can use the address the player reached.
     */
    public function playUrl(?string $host = null): ?string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $url = $metadata['play_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return $host !== null && $host !== '' ? str_replace('{{host}}', $host, $url) : $url;
    }

    /** `play_url` with the `{{host}}` template replaced by that host. */
    public function playUrlFor(string $host): ?string
    {
        return $this->playUrl($host);
    }

    /**
     * The one public server of a browser recipe, described by
     * `metadata.server` and joined through the HTTPS `play_url`. It is not a
     * `ServerLink` row: there is no process LANoMAT controls, just a page a
     * player opens. Returns null when the recipe has no such metadata, so a
     * normal installable recipe keeps its real server rows only.
     *
     * @return array<string, mixed>|null
     */
    public function browserServer(?string $host = null): ?array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $server = $metadata['server'] ?? null;

        if (! is_array($server)) {
            return null;
        }

        $name = trim((string) ($server['name'] ?? ''));
        $address = trim((string) ($server['address'] ?? ''));
        $port = filter_var($server['port'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $connect = $server['connect_url'] ?? $this->playUrl($host);

        if ($name === '' || $address === '' || $port === false
            || ! is_string($connect) || trim($connect) === '') {
            return null;
        }

        $scheme = mb_strtolower((string) parse_url($connect, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return [
            'id' => 0,
            'title' => $name,
            'game' => $this->title,
            'matchLabel' => null,
            'address' => $address,
            'port' => (int) $port,
            'connectString' => $connect,
            'connectIsLink' => true,
            'status' => 'ready',
            'query' => null,
            'event' => null,
        ];
    }

    /** The short "Ein Server · Nickname · Join/Leave" line of a browser recipe. */
    public function serverNote(): ?string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $note = data_get($metadata, 'server.players') ?? data_get($metadata, 'server.note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }

    /** Whether the recipe has a multiplayer mode (default true; a singleplayer recipe says so). */
    public function supportsMultiplayer(): bool
    {
        return (bool) ($this->metadata['multiplayer'] ?? true);
    }

    public function installCommand(): string
    {
        return 'lanomat-agent install '.$this->slug;
    }

    public function launchCommand(): string
    {
        return 'lanomat-agent launch '.$this->slug;
    }

    public function installDeepLink(): string
    {
        return 'lanomat://install/'.$this->slug;
    }

    public function launchDeepLink(): string
    {
        return 'lanomat://launch/'.$this->slug;
    }

    /** @return list<GameRecipeFile> */
    public function avatarFiles(): array
    {
        return array_values($this->files->filter(
            static fn (GameRecipeFile $file): bool => $file->role->isSelectable(),
        )->values()->all());
    }

    protected static function newFactory(): GameRecipeFactory
    {
        return GameRecipeFactory::new();
    }
}
