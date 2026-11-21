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
     * A local launch command from the recipe. Precedence: an explicitly
     * requested launch target, then a target for the requested OS, then the
     * recipe default, then the first declared target.
     */
    public function launchTarget(?string $id = null, ?string $os = null): ?string
    {
        $targets = $this->launchTargets();

        if ($id !== null) {
            foreach ($targets as $target) {
                if (($target['id'] ?? null) === $id && $this->targetCommand($target) !== null) {
                    return $this->targetCommand($target);
                }
            }
        }

        if ($os !== null) {
            foreach ($targets as $target) {
                $targetOs = $target['os'] ?? 'any';

                if ($targetOs === $os && $this->targetCommand($target) !== null) {
                    return $this->targetCommand($target);
                }
            }
        }

        $launch = $this->metadata['launch'] ?? null;

        if (is_string($launch) && $launch !== '') {
            return $launch;
        }

        if (is_array($launch) && $this->targetCommand($launch) !== null) {
            return $this->targetCommand($launch);
        }

        return $this->targetCommand($targets[0] ?? []);
    }

    /** @param array<string, mixed> $target */
    private function targetCommand(array $target): ?string
    {
        $command = $target['target'] ?? $target['command'] ?? null;

        return is_string($command) && $command !== '' ? $command : null;
    }

    /** @return list<array<string, mixed>> */
    public function launchTargets(): array
    {
        $targets = $this->metadata['launch_targets'] ?? [];

        return is_array($targets) ? array_values(array_filter($targets, 'is_array')) : [];
    }

    /** Optional web-playable URL (e.g. the Ridge Racer cabinet). */
    public function playUrl(?string $host = null): ?string
    {
        $url = $this->metadata['play_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return $host !== null && $host !== '' ? str_replace('{{host}}', $host, $url) : $url;
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
