<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\Concerns;

use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;

/**
 * The requirements repeater keeps the authored YAML shape; the LAN pin
 * (`shared_file_id`) is not part of it. Pins live on `game_recipe_files` and
 * are hydrated into the repeater when the form is filled, then written back
 * onto the normalized rows after the sync — so the JSON never carries a stale
 * pin and a deleted upload can not resurface as a phantom id.
 */
trait SyncsRequirementPins
{
    /** @var array<string, int|null> role|label => SharedFile id (null clears the pin) */
    protected array $requirementPins = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! is_array($data['requirements'] ?? null) || ! $this->record instanceof GameRecipe) {
            return $data;
        }

        $pins = $this->record->files
            ->mapWithKeys(fn (GameRecipeFile $file): array => [
                $file->role->value.'|'.$file->label => $file->shared_file_id,
            ])->all();

        foreach ($data['requirements'] as $index => $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            // Always hydrate from the file rows, never from the JSON: a pin
            // stored there by an older build can point at a deleted upload.
            $data['requirements'][$index]['shared_file_id'] = $pins[
                (string) ($requirement['role'] ?? '').'|'.(string) ($requirement['label'] ?? '')
            ] ?? null;
        }

        return $data;
    }

    /**
     * Removes the pins from the repeater state (they must not be persisted in
     * `requirements`) and remembers them for applyRequirementPins().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function captureRequirementPins(array $data): array
    {
        $this->requirementPins = [];

        if (! is_array($data['requirements'] ?? null)) {
            return $data;
        }

        foreach ($data['requirements'] as $index => $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $pin = $requirement['shared_file_id'] ?? null;
            $this->requirementPins[(string) ($requirement['role'] ?? '').'|'.(string) ($requirement['label'] ?? '')]
                = is_numeric($pin) ? (int) $pin : null;

            unset($data['requirements'][$index]['shared_file_id']);
        }

        return $data;
    }

    /** Writes the form's pins (including "no pin") onto the freshly synced rows. */
    protected function applyRequirementPins(GameRecipe $recipe): void
    {
        if ($this->requirementPins === []) {
            return;
        }

        foreach ($recipe->files()->get() as $file) {
            $key = $file->role->value.'|'.$file->label;

            if (! array_key_exists($key, $this->requirementPins)) {
                continue;
            }

            if ($file->shared_file_id !== $this->requirementPins[$key]) {
                $file->forceFill(['shared_file_id' => $this->requirementPins[$key]])->save();
            }
        }
    }
}
