<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Pages;

use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\PublishRecipe;
use App\Modules\GameRecipes\Actions\SyncRecipeFilesFromRequirements;
use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\Concerns\ResolvesReviewer;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\Concerns\SyncsRequirementPins;
use App\Modules\GameRecipes\Filament\Resources\Recipes\RecipeResource;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\ContributionFiles;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\ReviewStamp;
use App\Modules\GameRecipes\Jobs\ProposeRecipePullRequestJob;
use App\Modules\GameRecipes\Models\GameRecipe;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\HtmlString;

class EditRecipe extends EditRecord
{
    use ResolvesReviewer;
    use SyncsRequirementPins;

    protected static string $resource = RecipeResource::class;

    /** Status the record had before the running save, so a re-save never re-announces. */
    protected ?RecipeStatus $statusBeforeSave = null;

    /**
     * The status can also be set through the form. It is stamped only on a
     * transition into Published (see ReviewStamp); saving a published recipe
     * again keeps the reviewer who released it. A contributed recipe that is
     * released here joins the player lineup, exactly as the publish action
     * does, and afterSave dispatches RecipePublished once.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->captureRequirementPins($data);

        $this->statusBeforeSave = $this->record instanceof GameRecipe ? $this->record->status : null;
        $data = ReviewStamp::forTransition($data, $this->statusBeforeSave);

        if ($this->record instanceof GameRecipe && $this->record->isContribution() && $this->releasesIn($data)) {
            $data['in_lineup'] = true;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->record;

        if (! $record instanceof GameRecipe) {
            return;
        }

        app(SyncRecipeFilesFromRequirements::class)->handle($record);
        $this->applyRequirementPins($record);

        if ($this->statusBeforeSave !== RecipeStatus::Published && $record->status === RecipeStatus::Published) {
            app(PublishRecipe::class)->releaseAfterFormSave($record, $this->reviewer());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // The one-step handoff: approve the shared file and publish the
            // recipe together. Visible while a pinned file still waits or a
            // rejected one blocks the release.
            Action::make('publishWithFiles')
                ->label(__('game_recipes.actions.publish_with_files'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (GameRecipe $record): bool => $record->status !== RecipeStatus::Published
                    && $this->canApprovePinnedFiles($record)
                    && (ContributionFiles::pending($record)->isNotEmpty() || ContributionFiles::rejected($record)->isNotEmpty()))
                ->authorize('review')
                ->requiresConfirmation()
                ->modalHeading(__('game_recipes.actions.publish_with_files'))
                ->modalDescription(fn (GameRecipe $record): HtmlString => new HtmlString(implode('<br>', [
                    e(__('game_recipes.actions.publish_with_files_confirm')),
                    ...array_map(
                        static fn (string $line): string => '• '.e($line),
                        ContributionFiles::describe(ContributionFiles::pending($record)),
                    ),
                ])))
                ->modalSubmitActionLabel(__('game_recipes.actions.publish_with_files_submit'))
                ->disabled(fn (GameRecipe $record): bool => ContributionFiles::rejected($record)->isNotEmpty())
                ->tooltip(fn (GameRecipe $record): ?string => ContributionFiles::rejected($record)->isNotEmpty()
                    ? (string) __('game_recipes.actions.publish_with_files_rejected')
                    : null)
                ->action(function (GameRecipe $record): void {
                    $approved = ContributionFiles::pending($record)->count();

                    try {
                        app(PublishRecipe::class)->handle($record, $this->reviewer(), approveFiles: true);
                    } catch (AuthorizationException) {
                        // The Files module's own gate refused: nothing was
                        // published (one transaction), so say so instead of
                        // half-succeeding silently.
                        Notification::make()
                            ->title(__('game_recipes.actions.publish_with_files_forbidden'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshFormData(['status', 'in_lineup']);

                    Notification::make()
                        ->title(trans_choice('game_recipes.actions.published_with_files', $approved, ['count' => $approved]))
                        ->success()
                        ->send();

                    // A rejected pin can only survive a race (the action is
                    // disabled while one exists): name it instead of pretending
                    // the release was complete.
                    $record->load('files.sharedFile');
                    $rejected = ContributionFiles::rejected($record);

                    if ($rejected->isNotEmpty()) {
                        Notification::make()
                            ->title(__('game_recipes.actions.publish_with_files_left_rejected'))
                            ->body(implode(', ', ContributionFiles::describe($rejected)))
                            ->warning()
                            ->send();
                    }
                }),
            // Visible only while the recipe is not published, so both actions
            // are always a status transition; the policy decides who may make it.
            Action::make('publish')
                ->label(__('game_recipes.actions.publish'))
                ->visible(fn (GameRecipe $record): bool => $record->status !== RecipeStatus::Published)
                ->authorize('review')
                ->requiresConfirmation()
                ->action(function (GameRecipe $record): void {
                    app(PublishRecipe::class)->handle($record, $this->reviewer());

                    $this->refreshFormData(['status', 'in_lineup']);

                    Notification::make()
                        ->title(__('game_recipes.actions.published'))
                        ->success()
                        ->send();
                }),
            Action::make('proposePullRequest')
                ->label(__('game_recipes.actions.propose_pull_request'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (GameRecipe $record): bool => app(RecipeRepository::class)->isConfigured()
                    && $this->reviewer()->can('review', $record))
                ->authorize('review')
                ->requiresConfirmation()
                ->modalHeading(__('game_recipes.actions.propose_pull_request'))
                ->modalDescription(__('game_recipes.actions.propose_pull_request_help'))
                ->action(function (GameRecipe $record): void {
                    $this->requestPullRequest($record);
                }),
            Action::make('archive')
                ->label(__('game_recipes.actions.archive'))
                ->visible(fn (GameRecipe $record): bool => $record->status === RecipeStatus::Published)
                ->authorize('review')
                ->requiresConfirmation()
                ->action(function (GameRecipe $record): void {
                    // Archiving keeps the release stamp: it still says who
                    // published this recipe and when.
                    $record->forceFill(['status' => RecipeStatus::Archived])->save();
                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * Hands the recipe to the pull request pipeline. The job exports the text,
     * runs the public-safety check and opens or updates the one pull request;
     * the result shows up in the "Katalog (Pull Request)" section.
     */
    private function requestPullRequest(GameRecipe $record): void
    {
        ProposeRecipePullRequestJob::dispatch((int) $record->getKey());

        Notification::make()
            ->title(__('game_recipes.actions.propose_requested'))
            ->body(__('game_recipes.actions.propose_requested_help'))
            ->success()
            ->send();
    }

    /**
     * Whether the saved form flips the recipe into Published right now.
     *
     * @param  array<string, mixed>  $data
     */
    private function releasesIn(array $data): bool
    {
        if ($this->statusBeforeSave === RecipeStatus::Published) {
            return false;
        }

        $status = $data['status'] ?? null;

        if ($status instanceof RecipeStatus) {
            return $status === RecipeStatus::Published;
        }

        return is_string($status) && $status === RecipeStatus::Published->value;
    }

    /** Whether the current user may approve the files this recipe pins (asks the Files policy). */
    private function canApprovePinnedFiles(GameRecipe $record): bool
    {
        $file = collect(ContributionFiles::pinned($record))->first();

        return $file instanceof SharedFile && $this->reviewer()->can('approve', $file);
    }
}
