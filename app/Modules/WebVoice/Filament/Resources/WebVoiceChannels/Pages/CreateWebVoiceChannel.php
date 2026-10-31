<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Pages;

use App\Models\User;
use App\Modules\WebVoice\Actions\CreateGeneralChannel;
use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\WebVoiceChannelResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateWebVoiceChannel extends CreateRecord
{
    protected static string $resource = WebVoiceChannelResource::class;

    /**
     * `kind` is not fillable, so channel creation goes through the action that owns it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $admin */
        $admin = Auth::user();

        return app(CreateGeneralChannel::class)->handle($admin, [
            'name' => (string) $data['name'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
        ]);
    }
}
