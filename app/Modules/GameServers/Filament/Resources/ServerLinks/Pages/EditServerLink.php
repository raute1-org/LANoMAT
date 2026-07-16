<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\ServerLinks\Pages;

use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Filament\Resources\ServerLinks\ServerLinkResource;
use App\Modules\GameServers\Models\ServerLink;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Flattens/hydrates `join_info` (a JoinInfo value object, see JoinInfoCast)
 * into the form's flat address/port/password/connect_string fields —
 * mirrors EditGame's default_server_config handling.
 */
class EditServerLink extends EditRecord
{
    protected static string $resource = ServerLinkResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $joinInfo = $data['join_info'] ?? null;

        if ($joinInfo instanceof JoinInfo) {
            $data['address'] = $joinInfo->address;
            $data['port'] = $joinInfo->port;
            $data['password'] = $joinInfo->password;
            $data['connect_string'] = $joinInfo->connectString;
        }

        unset($data['join_info']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $joinInfo = new JoinInfo(
            address: $data['address'] ?? null,
            port: isset($data['port']) ? (int) $data['port'] : null,
            password: $data['password'] ?? null,
            connectString: $data['connect_string'] ?? null,
        );

        unset($data['address'], $data['port'], $data['password'], $data['connect_string']);

        $record->update($data);

        if ($record instanceof ServerLink) {
            $record->join_info = $joinInfo;
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
