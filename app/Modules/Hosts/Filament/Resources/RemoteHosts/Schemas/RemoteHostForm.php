<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Filament\Resources\RemoteHosts\Schemas;

use App\Modules\Events\Models\Event;
use App\Modules\Hosts\Enums\HostRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RemoteHostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('hosts.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('hostname')
                    ->label(__('hosts.fields.hostname'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('ssh_port')
                    ->label(__('hosts.fields.ssh_port'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->default(22),
                TextInput::make('ssh_user')
                    ->label(__('hosts.fields.ssh_user'))
                    ->required()
                    ->maxLength(255),

                // The SSH private key is write-only: it is never hydrated
                // back from the encrypted model attribute (no
                // formatStateUsing), and an empty submission on edit is not
                // dehydrated at all, so it leaves the stored key untouched.
                // Persisting the actual value happens in CreateRemoteHost /
                // EditRemoteHost, since ssh_private_key is not fillable.
                Textarea::make('ssh_private_key')
                    ->label(__('hosts.fields.ssh_private_key'))
                    ->helperText(__('hosts.fields.ssh_private_key_help'))
                    ->rows(6)
                    ->extraInputAttributes(['class' => 'font-mono'])
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->columnSpanFull(),

                Select::make('role')
                    ->label(__('hosts.fields.role'))
                    ->options(collect(HostRole::cases())->mapWithKeys(
                        fn (HostRole $role): array => [$role->value => $role->label()],
                    ))
                    ->required()
                    ->default(HostRole::Generic->value),
                Select::make('event_id')
                    ->label(__('hosts.fields.event'))
                    ->relationship('event', 'name')
                    ->options(fn (): array => Event::query()->pluck('name', 'id')->all())
                    ->searchable()
                    ->native(false),
            ]);
    }
}
