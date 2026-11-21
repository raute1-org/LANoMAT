<?php

namespace App\Modules\Catering\Filament\Resources\Restaurants\Schemas;

use App\Modules\Catering\Enums\FoodPaymentMethod;
use App\Modules\Catering\Filament\Livewire\RestaurantMenuTable;
use App\Modules\Catering\Filament\Resources\FoodOrders\Schemas\FoodOrderForm;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Catering\Support\DishImageStore;
use App\Modules\Registration\Support\PaymentLink;
use App\Support\Images\InvalidImageException;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class RestaurantForm
{
    public static function configure(Schema $schema): Schema
    {
        $nul = FoodOrderForm::noNulBytes();

        return $schema->components([
            Section::make(__('catering.restaurant.sections.master'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('catering.restaurant.fields.name'))
                        ->required()
                        ->maxLength(255)
                        ->rules([$nul])
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            if (($get('slug') ?? '') === '' && $state !== null) {
                                $set('slug', Str::slug(Str::limit($state, 120, '')));
                            }
                        }),
                    TextInput::make('slug')
                        ->label(__('catering.restaurant.fields.slug'))
                        ->required()
                        ->maxLength(255)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique(Restaurant::class, 'slug', ignoreRecord: true),
                    Textarea::make('description')
                        ->label(__('catering.restaurant.fields.description'))
                        ->helperText(__('catering.restaurant.fields.description_help'))
                        ->rows(3)
                        ->maxLength(600)
                        ->rules([$nul])
                        ->columnSpanFull(),
                    // Re-encoded to a small WebP by DishImageStore (same rules as dish photos).
                    FileUpload::make('logo')
                        ->label(__('catering.restaurant.fields.logo'))
                        ->helperText(__('catering.restaurant.fields.logo_help'))
                        ->disk(DishImageStore::DISK)
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(3072)
                        ->imagePreviewHeight('96')
                        ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                            try {
                                return app(DishImageStore::class)->store((string) $file->getRealPath());
                            } catch (InvalidImageException) {
                                throw ValidationException::withMessages(['logo' => __('catering.validation.image_unreadable')]);
                            }
                        })
                        ->deleteUploadedFileUsing(fn (): null => null)
                        ->columnSpanFull(),
                ]),
            Section::make(__('catering.restaurant.sections.contact'))
                ->description(__('catering.restaurant.sections.contact_help'))
                ->columns(2)
                ->collapsible()
                ->schema([
                    TextInput::make('phone')->label(__('catering.restaurant.fields.phone'))->tel()->maxLength(64)->rules([$nul]),
                    TextInput::make('address')->label(__('catering.restaurant.fields.address'))->maxLength(255)->rules([$nul]),
                    TextInput::make('website')->label(__('catering.restaurant.fields.website'))->url()->startsWith('https://')->maxLength(255),
                    TextInput::make('order_url')->label(__('catering.restaurant.fields.order_url'))->url()->startsWith('https://')->maxLength(255),
                ]),
            Section::make(__('catering.restaurant.sections.payment'))
                ->description(__('catering.restaurant.sections.payment_help'))
                ->columns(2)
                ->collapsible()
                ->schema([
                    Select::make('default_payment_method')
                        ->label(__('catering.restaurant.fields.default_payment_method'))
                        ->options([
                            FoodPaymentMethod::Cash->value => __('catering.payment_method.cash'),
                            FoodPaymentMethod::Paypal->value => __('catering.payment_method.paypal'),
                        ])
                        ->live(),
                    TextInput::make('default_payment_link')
                        ->label(__('catering.restaurant.fields.default_payment_link'))
                        ->url()
                        ->maxLength(255)
                        ->placeholder('https://paypal.me/example-orga')
                        ->live(onBlur: true)
                        ->visible(fn (Get $get): bool => $get('default_payment_method') === FoodPaymentMethod::Paypal->value)
                        ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if ($value !== null && $value !== '' && ! PaymentLink::isValid(is_string($value) ? $value : null)) {
                                $fail(__('catering.validation.payment_link'));
                            }
                        }]),
                    Text::make(fn (Get $get): string => FoodOrderForm::paymentPreview($get('default_payment_link')))
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => $get('default_payment_method') === FoodPaymentMethod::Paypal->value),
                ]),
            Section::make(__('catering.restaurant.sections.notes'))
                ->collapsible()
                ->collapsed()
                ->schema([
                    Textarea::make('notes')
                        ->hiddenLabel()
                        ->label(__('catering.restaurant.fields.notes'))
                        ->rows(3)
                        ->maxLength(5000)
                        ->rules([$nul]),
                ]),
            Section::make(__('catering.restaurant.sections.menu'))
                ->description(__('catering.restaurant.sections.menu_help'))
                ->schema([
                    Livewire::make(RestaurantMenuTable::class, fn (?Restaurant $record): array => ['restaurantId' => $record?->getKey()])
                        ->key('restaurant-menu-table')
                        ->visible(fn (?Restaurant $record): bool => $record?->exists === true),
                    Text::make(__('catering.restaurant.sections.menu_after_create'))
                        ->visible(fn (?Restaurant $record): bool => $record?->exists !== true),
                ]),
        ]);
    }
}
