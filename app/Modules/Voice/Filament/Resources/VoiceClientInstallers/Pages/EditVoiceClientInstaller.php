<?php

declare(strict_types=1);

namespace App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Pages;

use App\Modules\Voice\Actions\SetCurrentInstaller;
use App\Modules\Voice\Filament\Resources\VoiceClientInstallers\VoiceClientInstallerResource;
use App\Modules\Voice\Models\VoiceClientInstaller;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVoiceClientInstaller extends EditRecord
{
    protected static string $resource = VoiceClientInstallerResource::class;

    /**
     * The upload field is never pre-filled with the existing stored path (no
     * formatStateUsing pulling `path` back into the form) — mirrors
     * EditRemoteHost's handling of the write-only ssh_private_key field. A
     * fresh upload replaces the file; leaving it empty keeps the existing
     * one untouched.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['installer_upload'], $data['installer_upload_name']);

        return $data;
    }

    /**
     * `path`/`original_name`/`is_current` are deliberately not fillable (see
     * the model's comment). An empty `installer_upload` submission (the
     * field's `dehydrated(fn ($state) => filled($state))` rule) means $data
     * never contains it, so the existing stored file is left untouched;
     * a non-empty submission replaces path+original_name. `is_current` is
     * always routed through SetCurrentInstaller so the single-current
     * invariant holds even when toggled from this page.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $path = $data['installer_upload'] ?? null;
        $originalName = $data['installer_upload_name'] ?? null;
        $isCurrent = (bool) ($data['is_current'] ?? false);
        unset($data['installer_upload'], $data['installer_upload_name'], $data['is_current']);

        $record->update($data);

        if ($record instanceof VoiceClientInstaller && $path !== null && $path !== '') {
            $record->forceFill([
                'path' => (string) $path,
                'original_name' => (string) ($originalName ?? $path),
            ])->save();
        }

        if ($record instanceof VoiceClientInstaller) {
            if ($isCurrent) {
                app(SetCurrentInstaller::class)->handle($record);
            } elseif ($record->is_current) {
                $record->forceFill(['is_current' => false])->save();
            }
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize('delete'),
        ];
    }
}
