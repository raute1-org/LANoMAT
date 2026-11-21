<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameServers\Contracts\GameInstallLinks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The recipe catalogue's answer to "how do I get the game this server runs?".
 *
 * Candidates are the published recipes the lineup allows — the same lists the
 * launcher and the web library show — loaded once per instance so a browser of
 * fifty servers still costs one catalogue query. The recipe itself is picked
 * by {@see RecipeGameMatcher}; {@see LauncherSupport} decides whether the
 * launcher can install it and {@see LauncherJoinLink} builds the one-click
 * join, or nothing when the server has nothing to join.
 */
final class RecipeInstallLinks implements GameInstallLinks
{
    /** @var Collection<int, GameRecipe>|null the published, lineup-allowed catalogue, read once per instance */
    private ?Collection $candidates = null;

    public function __construct(
        private readonly LauncherLineup $lineup,
        private readonly LauncherSupport $support,
        private readonly RecipeGameMatcher $matcher,
        private readonly LauncherJoinLink $joinLink,
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
        ];
    }

    /**
     * The published catalogue the players may see. Memoised because every
     * server of a page asks the same question.
     *
     * The lineup is the configured allowlist plus every recipe the orga
     * switched on in the panel, exactly {@see LauncherLineup::slugs()}; both
     * are read in the same query as the published recipes. An empty configured
     * lineup stays "unrestricted" and keeps the whole catalogue.
     *
     * @return Collection<int, GameRecipe>
     */
    private function candidates(): Collection
    {
        if ($this->candidates instanceof Collection) {
            return $this->candidates;
        }

        $query = GameRecipe::query()
            ->published()
            ->orderBy('title')
            ->orderBy('id');

        $configured = $this->lineup->configuredSlugs();

        if ($configured !== []) {
            $query->where(function (Builder $lineup) use ($configured): void {
                $lineup->whereIn('slug', $configured)->orWhere('in_lineup', true);
            });
        }

        return $this->candidates = $query->get();
    }
}
