<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Models\User;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\PortableRecipeBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Spiel teilen": a participant pins one of their LAN archives, describes the
 * game, and gets a draft portable recipe. The orga approves the file and
 * publishes the recipe; nothing here publishes anything.
 *
 * The action owns the refusals a form cannot phrase (a foreign or rejected
 * file, an unusable archive, a slug somebody else already owns) and maps the
 * recipe validator's problems onto the title field.
 */
final class ShareGame
{
    /** A recipe slug the /games/share page itself would shadow. */
    public const RESERVED_SLUG = 'share';

    public function __construct(
        private readonly ContributeRecipe $contribute,
        private readonly PortableRecipeBuilder $builder,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(User $user, array $input): GameRecipe
    {
        $file = $this->file($user, $input);
        $title = trim((string) ($input['title'] ?? ''));
        $slug = Str::slug($title);

        if ($slug === '' || $slug === self::RESERVED_SLUG) {
            throw ValidationException::withMessages([
                'title' => __('game_share.errors.slug_reserved'),
            ]);
        }

        // One recipe per slug: the same participant may edit their own draft,
        // but a published recipe (theirs or somebody else's) is never rewritten
        // from the share page.
        $existing = GameRecipe::query()->where('slug', $slug)->first();

        if ($existing instanceof GameRecipe && ! $this->isOwnDraft($existing, $user)) {
            throw ValidationException::withMessages([
                'title' => __('game_share.errors.slug_taken'),
            ]);
        }

        try {
            return $this->contribute->handle($user, $this->builder->build($input, $file));
        } catch (RecipeException $exception) {
            // The validator reports every problem in one pass; the share form
            // only has one field that can carry them.
            throw ValidationException::withMessages([
                'title' => $exception->summary(),
            ]);
        }
    }

    /**
     * The pinned file, refused unless the participant could download it, it was
     * not rejected, and it is an archive the recipe can unpack and name safely.
     *
     * @param  array<string, mixed>  $input
     */
    private function file(User $user, array $input): SharedFile
    {
        $id = filter_var($input['shared_file_id'] ?? null, FILTER_VALIDATE_INT);

        if ($id === false) {
            throw ValidationException::withMessages([
                'shared_file_id' => __('game_share.errors.file_missing'),
            ]);
        }

        $file = SharedFile::query()->find($id);

        if (! $file instanceof SharedFile) {
            throw ValidationException::withMessages([
                'shared_file_id' => __('game_share.errors.file_missing'),
            ]);
        }

        // The uploader's own rejected file gets the specific answer; anybody
        // else's rejected file stays indistinguishable from an unknown id.
        if ($file->user_id === $user->id && $file->visibility === FileVisibility::Rejected) {
            throw ValidationException::withMessages([
                'shared_file_id' => __('game_share.errors.file_rejected'),
            ]);
        }

        // The same ability an install uses: approved, or the caller's own
        // pending upload, never a rejected file or one of a non-public event.
        if (Gate::forUser($user)->denies('download', $file)) {
            throw ValidationException::withMessages([
                'shared_file_id' => __('game_share.errors.file_missing'),
            ]);
        }

        if (! PortableRecipeBuilder::isArchiveName($file->original_name)) {
            throw ValidationException::withMessages([
                'shared_file_id' => __('game_share.errors.file_type'),
            ]);
        }

        if (PortableRecipeBuilder::hasUnsafeArchiveName($file->original_name)) {
            throw ValidationException::withMessages([
                'shared_file_id' => __('game_share.errors.file_name'),
            ]);
        }

        if (! PortableRecipeBuilder::hasReliableMatchPattern($file->original_name)) {
            throw ValidationException::withMessages([
                'shared_file_id' => __('game_share.errors.file_name_pattern'),
            ]);
        }

        return $file;
    }

    private function isOwnDraft(GameRecipe $recipe, User $user): bool
    {
        return $recipe->contributor_user_id === $user->id && $recipe->status === RecipeStatus::Draft;
    }
}
