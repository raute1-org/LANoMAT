<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Domain\RecipeProposal;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\CatalogFile;
use App\Modules\GameRecipes\Support\CatalogReadiness;
use App\Modules\GameRecipes\Support\LauncherSupport;
use App\Modules\GameRecipes\Support\PublicSafeText;
use App\Modules\GameRecipes\Support\RecipeYamlExporter;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Send one recipe into the git catalogue: export its text, refuse it when the
 * text could not be public, then put `recipe.yaml`, `SKILL.md` and the
 * catalogue row on one branch and make sure exactly one open pull request
 * carries that branch. One branch and one pull request per recipe, so a later
 * proposal updates instead of opening a second one.
 *
 * Only recipe text travels. Game files stay on the LAN file host, and the pull
 * request names requirement labels, never file-host URLs or ids.
 */
final class ProposeRecipe
{
    /** A blocked or failed reason lands in `pr_error`; keep it readable in a page or a log. */
    public const int ERROR_MAX = 2000;

    public function __construct(
        private readonly RecipeRepository $repository,
        private readonly RecipeYamlExporter $exporter,
        private readonly LauncherSupport $launcher,
    ) {}

    public function handle(GameRecipe $recipe): GameRecipe
    {
        if (! $this->repository->isConfigured()) {
            return $recipe;
        }

        if (! $this->isProposable($recipe)) {
            return $recipe;
        }

        $export = $this->exporter->export($recipe);
        $title = $this->titleText($recipe);
        $body = $this->body($recipe, neutralise: true);
        $problems = $this->problemsOf($recipe, $export);

        if ($problems !== []) {
            return $this->block($recipe, $problems);
        }

        try {
            return $this->propose($recipe, $export, $title, $body);
        } catch (RecipeRepositoryException $exception) {
            return $this->fail($recipe, $exception);
        }
    }

    /**
     * What would keep this recipe out of the repository right now: the
     * catalogue rules its text does not meet and everything the public-safety
     * gate refuses. Reads nothing from the repository and writes nothing, so
     * a review screen or a contribution answer can show it before anyone asks
     * for a pull request. An empty list means the text is ready to be sent.
     *
     * The catalogue holds a draft to stricter rules than a published recipe
     * (3 to 6 steps, a "needs verification" note). `$as` answers the question
     * for another status than the stored one, e.g. "and once it is published?".
     *
     * @return list<string>
     */
    public function preview(GameRecipe $recipe, ?RecipeStatus $as = null): array
    {
        if ($as !== null && $as !== $recipe->status) {
            // An in-memory copy: the stored recipe keeps its status.
            $recipe = clone $recipe;
            $recipe->status = $as;
        }

        return $this->problemsOf($recipe, $this->exporter->export($recipe));
    }

    /**
     * Both commit titles are checked before the first request: the verb
     * depends on the base branch, the sensitive part is the recipe title. The
     * raw text is checked, not the escaped Markdown: escaping must not hide an
     * address from the gate.
     *
     * @param  array{yaml: string, skill: string}  $export
     * @return list<string>
     */
    private function problemsOf(GameRecipe $recipe, array $export): array
    {
        $rawTitle = $this->rawTitle($recipe);

        return [
            ...(new CatalogReadiness)->problems($export, $recipe->slug),
            ...$this->problems('recipe.yaml', $export['yaml']),
            ...$this->problems('SKILL.md', $export['skill']),
            ...$this->problems('the pull request title', 'feat(recipes): add '.$rawTitle),
            ...$this->problems('the pull request title', 'feat(recipes): update '.$rawTitle),
            ...$this->problems('the pull request body', $this->body($recipe, neutralise: false)),
        ];
    }

    /**
     * @param  array{yaml: string, skill: string}  $export
     */
    private function propose(GameRecipe $recipe, array $export, string $title, string $body): GameRecipe
    {
        $path = $this->path();
        $yamlPath = $path.'/'.$recipe->slug.'/recipe.yaml';

        // The message tells the reviewer whether this adds or updates a recipe.
        $existingYaml = $this->repository->read($yamlPath);
        $message = ($existingYaml === null ? 'feat(recipes): add ' : 'feat(recipes): update ').$title;

        $files = [
            $yamlPath => $export['yaml'],
            $path.'/'.$recipe->slug.'/SKILL.md' => $export['skill'],
        ];

        $catalog = $this->repository->read($path.'/_catalog.md');

        if ($catalog !== null) {
            try {
                $files[$path.'/_catalog.md'] = $this->catalogWithRow($catalog, $recipe);
            } catch (DomainException $exception) {
                // The base branch's catalogue has no table for this status: the
                // proposal cannot be complete, and the row cannot be invented.
                return $this->block($recipe, ['the catalogue: '.$exception->getMessage()]);
            }
        }

        if ($this->unchanged($files)) {
            // Nothing to commit: leave an open pull request as it is and write no state.
            return $recipe;
        }

        $proposal = new RecipeProposal(
            slug: $recipe->slug,
            branch: $this->branch($recipe),
            commitMessage: $message,
            title: $message,
            body: $body,
            files: $files,
        );

        $ref = $this->repository->propose($proposal);

        $recipe->forceFill([
            'pr_number' => $ref->number,
            'pr_url' => $ref->url,
            'pr_branch' => $ref->branch,
            'pr_state' => PullRequestState::Open,
            'pr_error' => null,
            'pr_synced_at' => now(),
        ])->save();

        Log::info('recipes.pull_request_proposed', [
            'slug' => $recipe->slug,
            'number' => $ref->number,
            'branch' => $ref->branch,
        ]);

        return $recipe;
    }

    /**
     * Contributed and panel recipes, plus anything a sync adopted that already
     * carries a pull request. An archived recipe is never proposed again: the
     * catalogue has no row for it, and its pull request is left as it is.
     */
    public function isProposable(GameRecipe $recipe): bool
    {
        if ($recipe->status === RecipeStatus::Archived) {
            return false;
        }

        return in_array($recipe->source, [GameRecipe::SOURCE_CONTRIBUTION, GameRecipe::SOURCE_PANEL], true)
            || $recipe->pr_number !== null;
    }

    /**
     * Why the automatic path (a share, an edit, a publish) must skip this
     * proposal, or null when it may go. Bounds the branches one participant
     * and the whole installation can open; the orga's manual command and the
     * panel's "propose now" do not call this.
     *
     * One cache/rate-limiter call for the per-contributor budget and one count
     * query for the ceiling; a skipped attempt writes no state.
     */
    public function limitReason(GameRecipe $recipe, ?int $contributorId): ?string
    {
        $key = null;

        if ($contributorId !== null) {
            $perHour = max(1, (int) config('recipes.pull_requests.per_user_hourly', 6));
            $key = 'recipes:pull-requests:user:'.$contributorId;

            if (RateLimiter::tooManyAttempts($key, $perHour)) {
                return 'per_user_hourly';
            }
        }

        // An update of a recipe whose pull request is still open moves that
        // branch; a new one counts against the ceiling.
        $hasOpenPullRequest = $recipe->pr_number !== null
            && $recipe->pr_state === PullRequestState::Open;

        if (! $hasOpenPullRequest) {
            $ceiling = max(1, (int) config('recipes.pull_requests.max_open', 30));
            $open = GameRecipe::query()
                ->where('pr_state', PullRequestState::Open->value)
                ->count();

            if ($open >= $ceiling) {
                return 'max_open';
            }
        }

        if ($key !== null) {
            RateLimiter::hit($key, 3600);
        }

        return null;
    }

    /**
     * The pull request body. English Markdown, for the repository's reviewers.
     *
     * Participant-authored values are escaped and shortened when
     * `$neutralise` is set; the raw form is only used for the safety gate.
     */
    private function body(GameRecipe $recipe, bool $neutralise): string
    {
        $text = new PublicSafeText;
        $clean = fn (string $value, int $limit = 200): string => $neutralise ? $text->markdown($value, $limit) : $value;

        $platforms = array_values(array_filter(
            array_map(static fn (mixed $platform): string => trim((string) $platform), (array) ($recipe->platforms ?? [])),
            static fn (string $platform): bool => $platform !== '',
        ));
        $platforms = $platforms === [] ? 'windows' : implode(', ', $platforms);

        $launcher = $this->launcher->supports($recipe)
            ? 'the LANoMAT launcher installs it (unpack the base archive, start a target)'
            : 'manual install (the launcher does not take this recipe from archive to running game)';

        $license = $clean(trim((string) ($recipe->license ?? '')));
        $summary = $clean(trim((string) ($recipe->summary ?? '')));
        $labels = array_map(static fn (string $label): string => $clean($label, 160), $this->requirementLabels($recipe));

        $heading = $neutralise ? $text->markdown($this->rawTitle($recipe), 120) : $this->rawTitle($recipe);

        $lines = [
            '## '.$heading,
            '',
            '**Slug:** `'.$recipe->slug.'`  ',
            '**Platforms:** '.$clean($platforms, 80).'  ',
            '**Status at the LAN:** '.$recipe->status->value.'  ',
            '**Launcher:** '.$launcher,
            '',
        ];

        if ($summary !== '') {
            $lines[] = $summary;
            $lines[] = '';
        }

        $lines[] = '### Required files';
        $lines[] = '';

        if ($labels === []) {
            $lines[] = 'No file requirements recorded.';
        } else {
            foreach ($labels as $label) {
                $lines[] = '- '.$label;
            }
        }

        $lines[] = '';
        $lines[] = '### Review';
        $lines[] = '';
        $lines[] = '- [ ] `node resources/recipes/schema/validate.mjs` is green';
        $lines[] = '- [ ] Tested on: '.$clean($platforms, 80);
        $lines[] = '- [ ] Licence checked: '.($license !== '' ? $license : 'game files are user-supplied');
        $lines[] = '';

        if ((bool) config('recipes.pull_requests.attribution')) {
            $name = $clean(trim((string) $recipe->contributor?->name), 80);

            if ($name !== '') {
                $lines[] = 'Shared by '.$name.' at the LAN.';
                $lines[] = '';
            }
        }

        $lines[] = 'Opened automatically by LANoMAT from a shared recipe. Game files are not part of this pull request; they stay on the LAN file host.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Requirement labels only: a file-host URL or an id would leak instance
     * data into the public repository.
     *
     * @return list<string>
     */
    private function requirementLabels(GameRecipe $recipe): array
    {
        $labels = [];
        $files = $recipe->files()->limit(50)->get();

        if ($files->isNotEmpty()) {
            foreach ($files as $file) {
                $label = trim((string) $file->label);
                $labels[] = $label !== '' ? $label : 'File';
            }
        } else {
            foreach (array_slice((array) ($recipe->requirements ?? []), 0, 50) as $requirement) {
                $label = trim((string) ($requirement['label'] ?? ''));

                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * The title as it goes into the pull request title and the commit
     * message. Neither is Markdown, so the text is cleaned, not escaped.
     */
    private function titleText(GameRecipe $recipe): string
    {
        $title = (new PublicSafeText)->plain($this->rawTitle($recipe), 120);

        return $title !== '' ? $title : $recipe->slug;
    }

    private function rawTitle(GameRecipe $recipe): string
    {
        $title = trim((string) $recipe->title);

        return $title !== '' ? $title : $recipe->slug;
    }

    /** @return list<string> */
    private function problems(string $what, string $text): array
    {
        return array_map(
            static fn (string $problem): string => $what.': '.$problem,
            (new PublicSafeText)->problems($text),
        );
    }

    /** @param array<string, string> $files */
    private function unchanged(array $files): bool
    {
        foreach ($files as $path => $content) {
            if ($this->repository->read($path) !== $content) {
                return false;
            }
        }

        return true;
    }

    private function catalogWithRow(string $catalog, GameRecipe $recipe): string
    {
        return (new CatalogFile)->withRow(
            $catalog,
            $recipe->slug,
            $recipe->status->value,
            $this->exporter->catalogRow($recipe),
        );
    }

    /**
     * A refused recipe never reaches the repository: the reason is stored where
     * the panel and the share page show it.
     *
     * @param  list<string>  $problems
     */
    private function block(GameRecipe $recipe, array $problems): GameRecipe
    {
        // A pull request that already exists keeps its state: the recipe was
        // sent once, and "Nicht gesendet" would hide the branch and drop the
        // recipe out of the status sync.
        $keepRemote = $recipe->pr_state?->existsRemotely() === true;

        $recipe->forceFill([
            'pr_state' => $keepRemote ? $recipe->pr_state : PullRequestState::Blocked,
            'pr_error' => mb_substr(implode("\n", $problems), 0, self::ERROR_MAX),
            'pr_synced_at' => now(),
        ])->save();

        Log::info('recipes.pull_request_blocked', [
            'slug' => $recipe->slug,
            'findings' => count($problems),
            'kept_state' => $keepRemote ? $recipe->pr_state?->value : null,
        ]);

        return $recipe;
    }

    /**
     * A repository that cannot take the proposal keeps a pull request that was
     * opened earlier; otherwise the recipe says it failed. A transient failure
     * is rethrown so the job may retry.
     */
    private function fail(GameRecipe $recipe, RecipeRepositoryException $exception): GameRecipe
    {
        $keepRemote = $recipe->pr_state?->existsRemotely() === true;

        $attributes = [
            'pr_state' => $keepRemote ? $recipe->pr_state : PullRequestState::Failed,
            'pr_error' => mb_substr($exception->getMessage(), 0, self::ERROR_MAX),
        ];

        if (! $keepRemote) {
            $attributes['pr_synced_at'] = now();
        }

        $recipe->forceFill($attributes)->save();

        Log::warning('recipes.pull_request_failed', [
            'slug' => $recipe->slug,
            'transient' => $exception->transient,
            'reason' => $exception->getMessage(),
        ]);

        if ($exception->transient) {
            throw $exception;
        }

        return $recipe;
    }

    private function path(): string
    {
        return rtrim((string) config('recipes.pull_requests.path', 'resources/recipes'), '/');
    }

    private function branch(GameRecipe $recipe): string
    {
        $prefix = trim((string) config('recipes.pull_requests.branch_prefix', 'recipes/'));
        $prefix = $prefix === '' ? 'recipes/' : rtrim($prefix, '/').'/';

        return $prefix.$recipe->slug;
    }
}
