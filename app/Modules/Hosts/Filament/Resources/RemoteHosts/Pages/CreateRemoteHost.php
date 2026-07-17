<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Filament\Resources\RemoteHosts\Pages;

use App\Modules\Hosts\Filament\Resources\RemoteHosts\RemoteHostResource;
use App\Modules\Hosts\Models\RemoteHost;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRemoteHost extends CreateRecord
{
    protected static string $resource = RemoteHostResource::class;

    /**
     * `ssh_private_key` is deliberately not in RemoteHost::$fillable (see the
     * model's comment), so it cannot be set via the default mass-assignment
     * constructor. The admin Filament layer is already gated by
     * RemoteHostPolicy::create, so it is the one place allowed to assign it
     * explicitly, going through the `encrypted` cast exactly as any other
     * write would. Mirrors CreateGame::handleRecordCreation.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $privateKey = $data['ssh_private_key'] ?? '';
        unset($data['ssh_private_key']);

        $record = new RemoteHost($data);
        $record->ssh_private_key = (string) $privateKey;
        $record->save();

        return $record;
    }
}
