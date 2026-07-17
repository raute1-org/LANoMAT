<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Filament\Resources\RemoteHosts\Pages;

use App\Modules\Hosts\Filament\Resources\RemoteHosts\RemoteHostResource;
use App\Modules\Hosts\Models\RemoteHost;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRemoteHost extends EditRecord
{
    protected static string $resource = RemoteHostResource::class;

    /**
     * The SSH-key field on RemoteHostForm is never pre-filled (no
     * formatStateUsing pulling the encrypted attribute back into the form —
     * see the form's comment), so it always starts empty on this page.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['ssh_private_key']);

        return $data;
    }

    /**
     * `ssh_private_key` is deliberately not in RemoteHost::$fillable (see the
     * model's comment). Its `dehydrated(fn ($state) => filled($state))` rule
     * means an untouched (empty) field never reaches $data here at all, so
     * the existing stored key is left untouched; a non-empty submission
     * replaces it, going through the `encrypted` cast exactly as any other
     * write would. Mirrors EditGame::handleRecordUpdate.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $privateKey = $data['ssh_private_key'] ?? null;
        unset($data['ssh_private_key']);

        $record->update($data);

        if ($record instanceof RemoteHost && $privateKey !== null && $privateKey !== '') {
            $record->ssh_private_key = $privateKey;
            $record->save();
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
