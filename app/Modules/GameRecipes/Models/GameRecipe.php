<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Models;

use App\Models\User;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use Illuminate\Database\Eloquent\Builder;
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
 */
class GameRecipe extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'aliases',
        'version',
        'status',
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

            $command = $entry['command'] ?? null;

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
     * The command for one launch target: by id when given, otherwise the first
     * (recommended) target. Null when the recipe carries no launch data, or
     * when the requested id does not exist.
     */
    public function launchTarget(?string $id = null): ?string
    {
        $targets = $this->launchTargets();

        if ($targets === []) {
            return null;
        }

        if ($id === null) {
            return $targets[0]['command'];
        }

        foreach ($targets as $target) {
            if ($target['id'] === $id) {
                return $target['command'];
            }
        }

        return null;
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

        $target = $launch['target'] ?? null;

        if (! is_string($target) || trim($target) === '') {
            return null;
        }

        $args = $launch['args'] ?? null;
        $argLine = is_array($args)
            ? implode(' ', array_map(static fn (mixed $arg): string => (string) $arg, $args))
            : (is_string($args) ? $args : '');

        return match ($launch['kind'] ?? null) {
            // Steam takes the app id, not a path: steam://rungameid/<appid>//<args>
            'steam' => 'steam://rungameid/'.trim($target).($argLine !== '' ? '//'.$argLine : ''),
            'uri' => trim($target),
            default => trim($target.($argLine !== '' ? ' '.$argLine : '')),
        };
    }

    /** Optional web-playable URL (e.g. the Ridge Racer cabinet). */
    public function playUrl(): ?string
    {
        $url = $this->metadata['play_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * `play_url` with the `{{host}}` template replaced by the host that serves
     * the game, so a recipe never hardcodes `localhost`.
     */
    public function playUrlFor(string $host): ?string
    {
        $url = $this->playUrl();

        return $url === null ? null : str_replace('{{host}}', $host, $url);
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
}
