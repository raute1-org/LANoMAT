<?php

declare(strict_types=1);

namespace App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Pages;

use App\Modules\Voice\Actions\SetCurrentInstaller;
use App\Modules\Voice\Filament\Resources\VoiceClientInstallers\VoiceClientInstallerResource;
use App\Modules\Voice\Models\VoiceClientInstaller;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVoiceClientInstaller extends CreateRecord
{
    protected static string $resource = VoiceClientInstallerResource::class;

    /**
     * `path` and `original_name` are deliberately not in
     * VoiceClientInstaller::$fillable (see the model's comment), so they
     * cannot be set via the default mass-assignment constructor. The form's
     * `installer_upload` (the stored path, via Filament's disk-backed
     * FileUpload) and `installer_upload_name` (the original client filename,
     * via `storeFileNamesIn()`) are pulled out of $data here and forceFill()'d
     * onto the record explicitly — mirrors CreateRemoteHost's handling of
     * ssh_private_key.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $path = $data['installer_upload'] ?? null;
        $originalName = $data['installer_upload_name'] ?? null;
        $isCurrent = (bool) ($data['is_current'] ?? false);
        unset($data['installer_upload'], $data['installer_upload_name'], $data['is_current']);

        $record = new VoiceClientInstaller($data);
        $record->forceFill([
            'path' => (string) $path,
            'original_name' => (string) ($originalName ?? $path),
            'is_current' => false,
        ]);
        $record->save();

        // Routed through SetCurrentInstaller so a fresh "current" upload
        // unsets any previous current for the same (provider, platform) —
        // the same invariant the Filament table's "make current" row action
        // enforces, see that action's own comment.
        if ($isCurrent) {
            app(SetCurrentInstaller::class)->handle($record);
        }

        return $record;
    }
}
