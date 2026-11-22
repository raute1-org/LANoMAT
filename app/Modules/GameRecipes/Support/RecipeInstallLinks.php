<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameServers\Contracts\GameInstallLinks;
use Illuminate\Support\Collection;

/**
 * The recipe catalogue's answer to "how do I get the game this server runs?".
 *
 * Candidates are the published recipes the lineup allows — the same lists the
 * launcher and the web library show — loaded once per instance so a browser of
 * fifty servers still costs one catalogue query and one query for its file
 * rows. The lineup rule is {@see LauncherLineup::allowsSlug()}, applied to the
 * rows that query already returned, so it cannot differ from
 * {@see LauncherLineup::allows()} through a case-sensitive SQL comparison.
 *
 * The recipe itself is picked by {@see RecipeGameMatcher};
 * {@see LauncherSupport} decides whether the launcher can install it, and
 * {@see LauncherJoinLink} builds the one-click join, or nothing when the
 * server has nothing to join.
 */
final class RecipeInstallLinks implements GameInstallLinks
{
    /** @var Collection<int, GameRecipe>|null the published, lineup-allowed catalogue, read once per instance */
    private ?Collection $candidates = null;

    /** @var Collection<int, SharedFile>|null the approved LAN uploads any recipe may use, read once per instance */
    private ?Collection $lanFiles = null;

    /** @var array<int, bool> `lanReady` per recipe id, answered once per recipe per instance */
    private array $ready = [];

    public function __construct(
        private readonly LauncherLineup $lineup,
        private readonly LauncherSupport $support,
        private readonly RecipeGameMatcher $matcher,
        private readonly LauncherJoinLink $joinLink,
        private readonly RecipeFileMatcher $recipeFiles,
    ) {}

    public function forServer(?string $gameSlug, ?string $gameName, ?string $address, ?int $port, ?string $joinUrl): ?array
    {
        // A server without a game identity has no recipe to look for.
        if (trim((string) $gameSlug) === '' && trim((string) $gameName) === '') {
            return null;
        }

        $recipe = $this->matcher->find($this->candidates(), $gameSlug, $gameName);

        if (! $recipe instanceof GameRecipe) {
            return null;
        }

        $supported = $this->support->supports($recipe);

        return [
            'recipeSlug' => $recipe->slug,
            'title' => $recipe->title,
            'pageUrl' => '/games/'.$recipe->slug,
            'launcherSupported' => $supported,
            'launcherJoinUrl' => $supported ? $this->joinLink->build($recipe->slug, $address, $port, $joinUrl) : null,
            'lanReady' => $this->lanReady($recipe),
        ];
    }

    /**
     * Whether every required base or disc image of the recipe can be fetched
     * right now: an approved upload of a non-draft event is on the LAN, or the
     * requirement names an external download.
     *
     * This is the install plan's own rule ({@see RecipeFileMatcher}) asked
     * without a viewer and without an event. It differs from one concrete plan
     * only where that plan is narrower: the answer here spans the uploads of
     * every non-draft event (a plan for one event sees only that event's), and
     * it ignores the OS (a Windows-only base still counts for a Linux plan,
     * which skips it and installs from the disc image instead). An external
     * URL counts as ready although the plan lists the file as "missing
     * (external download only)": the player can get the game, just not from
     * the LAN.
     *
     * Requirement rows are the plan's source, so they decide. A recipe whose
     * rows never arrived is answered from its authored `requirements`, the way
     * the panel's review does; a base or disc image counts as required unless
     * the author said otherwise. Optional requirements, patches, tools, mods
     * and avatars never make a game "not ready": they are not the game.
     *
     * @see GameInstallLinks::forServer() for the per-kind answer
     */
    private function lanReady(GameRecipe $recipe): bool
    {
        return $this->ready[$recipe->id] ??= $this->everyRequirementAvailable($recipe);
    }

    private function everyRequirementAvailable(GameRecipe $recipe): bool
    {
        if ($recipe->files->isNotEmpty()) {
            foreach ($recipe->files as $entry) {
                if (! in_array($entry->role, [RecipeFileRole::Base, RecipeFileRole::Iso], true) || ! $entry->required) {
                    continue;
                }

                if (! $this->available($entry->shared_file_id, $entry->patterns(), $entry->url)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($recipe->requirements ?? [] as $requirement) {
            $role = (string) ($requirement['role'] ?? '');

            if (! in_array($role, [RecipeFileRole::Base->value, RecipeFileRole::Iso->value], true)) {
                continue;
            }

            // The parser's default: a base or disc image is required unless it says otherwise.
            if (! (bool) ($requirement['required'] ?? true)) {
                continue;
            }

            $pin = isset($requirement['shared_file_id']) && is_numeric($requirement['shared_file_id'])
                ? (int) $requirement['shared_file_id']
                : null;
            $patterns = array_values(array_filter(array_map('strval', (array) ($requirement['match'] ?? []))));
            $url = is_string($requirement['url'] ?? null) ? $requirement['url'] : null;

            if (! $this->available($pin, $patterns, $url)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One requirement's answer, the rule {@see RecipeFileMatcher::match()}
     * applies: the pinned upload wins, otherwise a filename pattern matches an
     * approved upload of a non-draft event, and an external URL is a source
     * the player can follow.
     *
     * @param  list<string>  $patterns
     */
    private function available(?int $pinnedId, array $patterns, ?string $url): bool
    {
        if ($url !== null && trim($url) !== '') {
            return true;
        }

        $files = $this->lanFiles();

        if ($pinnedId !== null && $files->contains(static fn (SharedFile $file): bool => $file->id === $pinnedId)) {
            return true;
        }

        return $patterns !== []
            && $files->contains(fn (SharedFile $file): bool => $this->recipeFiles->nameMatches($file->original_name, $patterns));
    }

    /**
     * The approved uploads of every non-draft event: the same candidate set
     * {@see RecipeFileMatcher} searches, read once per instance. `lanReady`
     * must not depend on who looks, so no upload is filtered by its owner
     * (an upload only its owner may download still needs a signed link, but
     * the orga can see it on the LAN).
     *
     * @return Collection<int, SharedFile>
     */
    private function lanFiles(): Collection
    {
        return $this->lanFiles ??= $this->recipeFiles->query(null)
            ->without('event')
            ->get(['id', 'original_name']);
    }

    /**
     * The published catalogue the players may see. Memoised because every
     * server of a page asks the same question.
     *
     * The lineup is the configured allowlist plus every recipe the orga
     * switched on in the panel, exactly {@see LauncherLineup::slugs()}. Both
     * are decided on the rows of the same query: an empty configured lineup
     * stays "unrestricted" and keeps the whole catalogue.
     *
     * @return Collection<int, GameRecipe>
     */
    private function candidates(): Collection
    {
        if ($this->candidates instanceof Collection) {
            return $this->candidates;
        }

        $recipes = GameRecipe::query()
            ->published()
            ->with('files')
            ->orderBy('title')
            ->orderBy('id')
            ->get();

        if ($this->lineup->isUnrestricted()) {
            return $this->candidates = $recipes;
        }

        return $this->candidates = $recipes
            ->filter(fn (GameRecipe $recipe): bool => $this->lineup->allowsSlug($recipe->slug, $recipe->in_lineup))
            ->values();
    }
}
