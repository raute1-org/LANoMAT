<?php

declare(strict_types=1);

namespace App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Schemas;

use App\Modules\Voice\Domain\VoiceClientPlatform;
use App\Modules\Voice\Domain\VoiceProvider;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VoiceClientInstallerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('provider')
                    ->label(__('voice.resource.fields.provider'))
                    ->options(collect(VoiceProvider::cases())
                        ->mapWithKeys(fn (VoiceProvider $provider): array => [$provider->value => $provider->label()])
                        ->all())
                    ->required()
                    ->native(false),
                Select::make('platform')
                    ->label(__('voice.resource.fields.platform'))
                    ->options(collect(VoiceClientPlatform::cases())
                        ->mapWithKeys(fn (VoiceClientPlatform $platform): array => [$platform->value => $platform->label()])
                        ->all())
                    ->required()
                    ->native(false),
                TextInput::make('version')
                    ->label(__('voice.resource.fields.version'))
                    ->required()
                    ->maxLength(64),

                // `path`/`original_name` are not fillable (see the model's
                // comment) — this virtual field is only the upload widget;
                // CreateVoiceClientInstaller/EditVoiceClientInstaller pull
                // the stored path and, via storeFileNamesIn(), the original
                // client filename out of $data and forceFill() them onto the
                // record, mirroring RemoteHostForm's ssh_private_key
                // handling of a non-fillable field.
                FileUpload::make('installer_upload')
                    ->label(__('voice.resource.fields.installer_upload'))
                    ->disk('local')
                    ->directory('voice-installers')
                    ->storeFileNamesIn('installer_upload_name')
                    ->preserveFilenames()
                    // Client installers are large binaries; lift Filament's
                    // implicit cap to 1 GB (KB units). Mirrors the Livewire
                    // temporary-upload rule (config/livewire.php) and must stay
                    // within the PHP upload_max_filesize/post_max_size.
                    ->maxSize(1048576)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(__('voice.resource.fields.installer_upload_help')),

                Toggle::make('is_current')
                    ->label(__('voice.resource.fields.is_current'))
                    ->helperText(__('voice.resource.fields.is_current_help')),
            ]);
    }
}
