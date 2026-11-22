<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Models\User;
use App\Modules\Files\Actions\ApproveSharedFile;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Events\RecipePublished;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\PortableRecipeBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The one place that releases a recipe to the players. Every panel path
 * (header action, form save, create form) ends here, so the release stamp, the
 * lineup flag and the RecipePublished event can never drift apart:
 *
 * - the reviewer who released it is stamped only when the recipe *enters*
 *   Published (mirrors the Filament `ReviewStamp`),
 * - a contributed recipe joins the player lineup (`in_lineup`), which is how a
 *   shared game shows up on /games and in the launcher without a config change,
 * - a pending LAN file of the recipe may be approved in the same step, so the
 *   orga shares, approves and publishes in one action.
 *
 * Authorization stays with the caller (the panel action authorizes `review`);
 * the file approval keeps its own `approve` gate inside ApproveSharedFile, so a
 * refusal surfaces as an authorization failure instead of a half-published
 * recipe.
 */
final class PublishRecipe
{
    public function __construct(private readonly ApproveSharedFile $approveFile) {}

    /**
     * Publish the recipe. Returns the same model, updated.
     *
     * @param  bool  $approveFiles  also approve every pinned, still pending LAN file
     */
    public function handle(GameRecipe $recipe, User $reviewer, bool $approveFiles = false): GameRecipe
    {
        $transition = $recipe->status !== RecipeStatus::Published;

        DB::transaction(function () use ($recipe, $reviewer, $approveFiles, $transition): void {
            if ($approveFiles) {
                $this->approvePendingPins($recipe, $reviewer);
            }

            $this->release($recipe, $reviewer, $transition);
        });

        if ($transition) {
            RecipePublished::dispatch($recipe, $reviewer->id);
        }

        return $recipe;
    }

    /**
     * Second half of a release whose status the form already wrote: only the
     * effects a plain save cannot express (the lineup flag for a contribution
     * and the RecipePublished event). Called from the edit/create page after a
     * save that flipped the status into Published — never on a re-save, so the
     * event fires exactly once per release.
     */
    public function releaseAfterFormSave(GameRecipe $recipe, User $reviewer): GameRecipe
    {
        if ($recipe->status !== RecipeStatus::Published) {
            return $recipe;
        }

        DB::transaction(function () use ($recipe): void {
            $this->joinLineup($recipe);
            $this->dropDraftWording($recipe);
            $recipe->save();
        });

        RecipePublished::dispatch($recipe, $reviewer->id);

        return $recipe;
    }

    /**
     * Approve the pinned files that are still pending; approved and rejected pins are left alone.
     *
     * A rejected pin is never re-approved (its bytes are gone from the store):
     * the caller reports it, see ContributionFiles::rejected().
     */
    private function approvePendingPins(GameRecipe $recipe, User $reviewer): void
    {
        $recipe->files()->with('sharedFile')->get()->each(function (GameRecipeFile $file) use ($reviewer): void {
            $shared = $file->sharedFile;

            // A rejected file's bytes are gone and it can never come back; a
            // missing pin simply has nothing to approve.
            if (! $shared instanceof SharedFile || $shared->visibility !== FileVisibility::Pending) {
                return;
            }

            $this->approveFile->handle($shared, $reviewer);
        });
    }

    private function release(GameRecipe $recipe, User $reviewer, bool $transition): void
    {
        $attributes = ['status' => RecipeStatus::Published];

        if ($transition) {
            $attributes['reviewed_by'] = $reviewer->id;
            $attributes['reviewed_at'] = now();
        }

        $recipe->forceFill($attributes);
        $this->joinLineup($recipe);
        $this->dropDraftWording($recipe);
        $recipe->save();
    }

    /**
     * A shared game's generated text says "this draft needs verification"
     * (the catalogue asks a draft to). Published, that is no longer true, and
     * it is what players read under "Über das Spiel".
     */
    private function dropDraftWording(GameRecipe $recipe): void
    {
        if (! $recipe->isContribution()) {
            return;
        }

        $steps = array_map(static function (array $step): array {
            if (is_string($step['detail'] ?? null)) {
                $step['detail'] = PortableRecipeBuilder::publishedText($step['detail']);
            }

            return $step;
        }, $recipe->steps ?? []);

        $recipe->forceFill([
            'description' => $recipe->description !== null ? PortableRecipeBuilder::publishedText($recipe->description) : null,
            'skill_markdown' => $recipe->skill_markdown !== null ? PortableRecipeBuilder::publishedText($recipe->skill_markdown) : null,
            'steps' => $steps,
        ]);
    }

    private function joinLineup(GameRecipe $recipe): void
    {
        // Only a shared (contributed) game is added to the lineup; a curated
        // recipe keeps whatever the orga chose with the "In der Spieleliste"
        // switch.
        if ($recipe->isContribution()) {
            $recipe->forceFill(['in_lineup' => true]);
        }
    }
}
