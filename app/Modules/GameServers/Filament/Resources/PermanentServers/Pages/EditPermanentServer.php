<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\PermanentServers\Pages;

use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\QueryProtocol;
use App\Modules\GameServers\Filament\Resources\PermanentServers\PermanentServerResource;
use App\Modules\GameServers\Models\ServerLink;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Flattens/hydrates `join_info` into the form's address/port/connect_string
 * fields (the password of a permanent server is never edited here).
 */
class EditPermanentServer extends EditRecord
{
    protected static string $resource = PermanentServerResource::class;

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
            $data['connect_string'] = $joinInfo->connectString;
        }

        $protocol = $data['query_protocol'] ?? null;
        $data['query_protocol'] = $protocol instanceof QueryProtocol ? $protocol->value : $protocol;

        unset($data['join_info']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof ServerLink) {
            return $record;
        }

        $current = $record->join_info;
        $record->join_info = new JoinInfo(
            address: filled($data['address'] ?? null) ? (string) $data['address'] : null,
            port: filled($data['port'] ?? null) ? (int) $data['port'] : null,
            password: $current->password,
            connectString: filled($data['connect_string'] ?? null) ? (string) $data['connect_string'] : null,
        );
        $record->title = (string) $data['title'];
        $record->description = filled($data['description'] ?? null) ? (string) $data['description'] : null;
        $record->query_protocol = filled($data['query_protocol'] ?? null) ? QueryProtocol::from((string) $data['query_protocol']) : null;
        $record->save();

        return $record;
    }
}
