<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Schemas;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodPaymentMethod;
use App\Modules\Catering\Support\DishImageStore;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Support\PaymentLink;
use App\Support\Images\InvalidImageException;
use App\Support\Money\Euro;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class FoodOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label(__('catering.fields.event'))
                    ->relationship('event', 'name')
                    ->required()
                    ->searchable(),
                TextInput::make('title')
                    ->label(__('catering.fields.title'))
                    ->required()
                    ->maxLength(255)
                    ->rules([self::noNulBytes()]),
                DateTimePicker::make('opens_at')
                    ->label(__('catering.fields.opens_at')),
                DateTimePicker::make('closes_at')
                    ->label(__('catering.fields.closes_at')),
                Select::make('payment_method')
                    ->label(__('catering.fields.payment_method'))
                    ->options([
                        FoodPaymentMethod::Cash->value => __('catering.payment_method.cash'),
                        FoodPaymentMethod::Paypal->value => __('catering.payment_method.paypal'),
                    ])
                    ->default(FoodPaymentMethod::Cash->value)
                    ->required()
                    ->live(),
                TextInput::make('payment_link')
                    ->label(__('catering.fields.payment_link'))
                    ->url()
                    ->maxLength(255)
                    ->placeholder('https://paypal.me/example-orga')
                    ->visible(fn (Get $get): bool => $get('payment_method') === FoodPaymentMethod::Paypal->value)
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value !== null && $value !== '' && ! PaymentLink::isValid(is_string($value) ? $value : null)) {
                            $fail(__('catering.validation.payment_link'));
                        }
                    }]),
                Select::make('included_ticket_types')
                    ->label(__('catering.fields.included_ticket_types'))
                    ->helperText(__('catering.fields.included_ticket_types_help'))
                    ->multiple()
                    ->options(fn (Get $get): array => self::ticketOptions($get('event_id')))
                    ->default([])
                    ->hintActions([
                        Action::make('include_all')
                            ->label(__('catering.fields.include_all'))
                            ->action(fn (Get $get, Set $set) => $set('included_ticket_types', array_map(
                                'strval',
                                array_keys(self::ticketOptions($get('event_id'))),
                            ))),
                        Action::make('include_none')
                            ->label(__('catering.fields.include_none'))
                            ->action(fn (Set $set) => $set('included_ticket_types', [])),
                    ]),
                // A typed Repeater, deliberately not a KeyValue field: each
                // row maps 1:1 onto the MenuCast/MenuOption array shape
                // {key,name,price_cents} (see roadmap insight #9). The price is
                // typed in EUR ("8,50") and converted to integer cents on the
                // way in and back for display, so the stored value stays an int.
                Repeater::make('menu')
                    ->label(__('catering.fields.menu'))
                    ->schema([
                        TextInput::make('key')
                            ->label(__('catering.fields.menu_key'))
                            ->required()
                            // Matches the option_key limit of the order request (max 64).
                            ->maxLength(64)
                            ->rules([self::noNulBytes()]),
                        TextInput::make('name')
                            ->label(__('catering.fields.menu_name'))
                            ->required()
                            ->maxLength(255)
                            ->rules([self::noNulBytes()]),
                        TextInput::make('price_cents')
                            ->label(__('catering.fields.menu_price'))
                            ->required()
                            ->inputMode('decimal')
                            ->placeholder('8,50')
                            ->suffix('€')
                            ->formatStateUsing(fn (mixed $state): ?string => is_numeric($state)
                                ? number_format((int) $state / 100, 2, ',', '')
                                : null)
                            ->dehydrateStateUsing(fn (?string $state): ?int => self::euroToCents($state))
                            ->rules([self::priceRule()]),
                        Textarea::make('description')
                            ->label(__('catering.fields.menu_description'))
                            ->helperText(__('catering.fields.menu_description_help'))
                            ->rows(2)
                            ->maxLength(MenuOption::MAX_DESCRIPTION_LENGTH)
                            ->rules([self::noNulBytes()])
                            ->columnSpan(2),
                        // The upload is re-encoded to a small WebP by DishImageStore and filed under
                        // its content hash on the private disk. Files are shared between dishes and
                        // rounds, so removing one here only clears the reference.
                        FileUpload::make('image')
                            ->label(__('catering.fields.menu_image'))
                            ->helperText(__('catering.fields.menu_image_help'))
                            ->disk(DishImageStore::DISK)
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(3072)
                            ->imagePreviewHeight('96')
                            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                                try {
                                    return app(DishImageStore::class)->store((string) $file->getRealPath());
                                } catch (InvalidImageException) {
                                    throw ValidationException::withMessages(['image' => __('catering.validation.image_unreadable')]);
                                }
                            })
                            ->deleteUploadedFileUsing(fn (): null => null),
                    ])
                    ->columns(3)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->addActionLabel(__('catering.fields.menu_add'))
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->rules([self::uniqueMenuKeysRule()]),
            ]);
    }

    /**
     * ALL of the event's ticket types (inactive ones marked), keyed by their stable key — a round
     * may still include a since-deactivated key and must stay saveable.
     *
     * @return array<string, string>
     */
    public static function ticketOptions(mixed $eventId): array
    {
        if (! is_numeric($eventId)) {
            return [];
        }

        $event = Event::query()->find((int) $eventId);

        if ($event === null) {
            return [];
        }

        return TicketType::query()
            ->where('event_id', $event->id)
            ->orderBy('sort')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (TicketType $type): array => [
                $type->key => $type->active ? $type->name : $type->name.' '.__('registration.admin.ticket_types.inactive_suffix'),
            ])
            ->all();
    }

    /**
     * Converts a EUR amount typed with a decimal comma or point ("8,50", "8.5",
     * "9") into integer cents, without float arithmetic. Returns null for
     * anything that is not a plain amount with at most two decimals.
     */
    public static function euroToCents(?string $value): ?int
    {
        return Euro::toCents($value);
    }

    private static function priceRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $cents = self::euroToCents(is_string($value) ? $value : null);

            if ($cents === null) {
                $fail(__('catering.validation.price_format'));

                return;
            }

            if ($cents > MenuOption::MAX_PRICE_CENTS) {
                $fail(__('catering.validation.price_max', ['max' => '1.000,00']));
            }
        };
    }

    /**
     * Two options of one menu may not share a key: the first one used to win
     * silently and the counts merged (F-R2B-14).
     */
    private static function uniqueMenuKeysRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $keys = collect(is_array($value) ? $value : [])
                ->map(fn (mixed $row): mixed => is_array($row) ? ($row['key'] ?? null) : null)
                ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
                ->values();

            if ($keys->count() !== $keys->unique()->count()) {
                $fail(__('catering.validation.menu_key_duplicate'));
            }
        };
    }

    /**
     * PostgreSQL rejects NUL bytes in varchar and jsonb (F-R2B-12).
     */
    private static function noNulBytes(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && str_contains($value, "\0")) {
                $fail(__('catering.validation.no_nul_bytes'));
            }
        };
    }
}
