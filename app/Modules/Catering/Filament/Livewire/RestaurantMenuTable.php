<?php

namespace App\Modules\Catering\Filament\Livewire;

use App\Modules\Catering\Actions\UpdateRestaurantMenu;
use App\Modules\Catering\Domain\MenuChoice;
use App\Modules\Catering\Domain\MenuChoices;
use App\Modules\Catering\Domain\MenuImport;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Exceptions\MenuProtectedException;
use App\Modules\Catering\Filament\Resources\FoodOrders\Schemas\FoodOrderForm;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Catering\Support\DishImage;
use App\Modules\Catering\Support\DishImageStore;
use App\Modules\Catering\Support\GroupOrder;
use App\Support\Images\InvalidImageException;
use App\Support\Money\Euro;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The menu editor of a restaurant: one compact row per dish, grouped by the category prefix
 * ("Pizza: Margherita"), searchable and filterable, with one modal per dish (name, price,
 * description, photo). A 200-dish menu is a paged table, never 200 hydrated form blocks.
 *
 * Every write goes through UpdateRestaurantMenu, which enforces the ordered-dish protection;
 * the disabled delete button is only a courtesy.
 */
class RestaurantMenuTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $restaurantId;

    public function mount(int $restaurantId): void
    {
        $this->restaurantId = $restaurantId;
        Gate::authorize('update', $this->restaurant());
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, array $filters, int $page, int $recordsPerPage): LengthAwarePaginator {
                Gate::authorize('update', $this->restaurant());

                $rows = collect($this->rows());
                $category = $filters['category']['value'] ?? null;

                if (is_string($category) && $category !== '') {
                    $rows = $rows->where('category', $category);
                }

                if (is_string($search) && trim($search) !== '') {
                    $needle = mb_strtolower(trim($search));
                    $rows = $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['full_name'].' '.($row['description'] ?? '')), $needle));
                }

                return new LengthAwarePaginator(
                    $rows->forPage($page, $recordsPerPage),
                    total: $rows->count(),
                    perPage: $recordsPerPage,
                    currentPage: $page,
                );
            })
            ->columns([
                TextColumn::make('label')
                    ->label(__('catering.restaurant.menu.name'))
                    ->description(fn (array $record): ?string => $record['description'])
                    ->weight('semibold')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('price_cents')
                    ->label(__('catering.restaurant.menu.price'))
                    ->formatStateUsing(fn (mixed $state): string => Euro::format((int) $state))
                    ->alignEnd()
                    ->fontFamily('mono'),
                ImageColumn::make('image_url')
                    ->label(__('catering.restaurant.menu.photo'))
                    ->imageSize(48)
                    ->extraImgAttributes(fn (array $record): array => [
                        'loading' => 'lazy',
                        'alt' => $record['full_name'],
                        'style' => 'border-radius: 0.375rem; object-fit: cover;',
                    ])
                    ->placeholder(__('catering.restaurant.menu.no_photo')),
                TextColumn::make('choices_label')
                    ->label(__('catering.restaurant.menu.choices'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('–'),
                TextColumn::make('ordered')
                    ->label(__('catering.restaurant.menu.ordered'))
                    ->numeric()
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->placeholder('–')
                    ->formatStateUsing(fn (mixed $state): ?string => (int) $state > 0 ? (string) $state : null),
            ])
            ->groups([
                Group::make('category')
                    ->label(__('catering.restaurant.menu.category'))
                    ->collapsible(),
            ])
            ->defaultGroup('category')
            ->groupingSettingsHidden()
            ->filters([
                SelectFilter::make('category')
                    ->label(__('catering.restaurant.menu.category'))
                    ->options(fn (): array => collect($this->rows())->pluck('category', 'category')->all()),
            ])
            ->searchPlaceholder(__('catering.restaurant.menu.search'))
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(__('catering.restaurant.menu.empty'))
            ->emptyStateDescription(__('catering.restaurant.menu.empty_help'))
            ->headerActions([$this->addAction()])
            ->recordActions([$this->editAction(), $this->deleteAction()]);
    }

    public function render(): View
    {
        return view('filament.restaurants.menu-table');
    }

    /** @param  array<string, int|string>  $replace */
    private static function t(string $key, array $replace = []): string
    {
        $text = trans($key, $replace);

        return is_string($text) ? $text : $key;
    }

    private function restaurant(): Restaurant
    {
        return Restaurant::query()->findOrFail($this->restaurantId);
    }

    /**
     * @return array<string, array{key: string, category: string, label: string, full_name: string, price_cents: int, description: string|null, image_url: string|null, choices_label: string|null, ordered: int, protected: int, open: int}>
     */
    private function rows(): array
    {
        $restaurant = $this->restaurant();
        $ordered = $restaurant->orderedCounts();
        $protected = $restaurant->protectedCounts();
        $open = $restaurant->openCounts();
        $rows = [];

        foreach ($restaurant->menu as $option) {
            [$category, $label] = GroupOrder::split($option->name);
            $rows[$option->key] = [
                'key' => $option->key,
                'category' => $category,
                'label' => $label,
                'full_name' => $option->name,
                'price_cents' => $option->priceCents,
                'description' => $option->description,
                'image_url' => DishImage::previewUrl($restaurant, $option),
                'choices_label' => $option->isSetMenu() ? self::t('catering.restaurant.menu.choices_badge', ['count' => count($option->choices)]) : null,
                'ordered' => $ordered[$option->key] ?? 0,
                'protected' => $protected[$option->key] ?? 0,
                'open' => $open[$option->key] ?? 0,
            ];
        }

        return $rows;
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private function dishFields(bool $withReprice, ?string $selfKey = null): array
    {
        $fields = [
            TextInput::make('name')
                ->label(__('catering.restaurant.menu.name'))
                ->helperText(__('catering.restaurant.menu.name_help'))
                ->required()
                ->maxLength(MenuImport::MAX_NAME_LENGTH)
                ->rules([FoodOrderForm::noNulBytes()]),
            TextInput::make('price')
                ->label(__('catering.restaurant.menu.price'))
                ->required()
                ->inputMode('decimal')
                ->placeholder('8,50')
                ->suffix('€')
                ->live(onBlur: true)
                ->rules([FoodOrderForm::priceRule()]),
            Textarea::make('description')
                ->label(__('catering.restaurant.menu.description'))
                ->helperText(__('catering.fields.menu_description_help'))
                ->rows(2)
                ->maxLength(MenuOption::MAX_DESCRIPTION_LENGTH)
                ->rules([FoodOrderForm::noNulBytes()]),
            Hidden::make('preview_url'),
            Image::make(fn (Get $get): string => (string) $get('preview_url'), __('catering.restaurant.menu.photo_preview'))
                ->imageHeight('14rem')
                ->visible(fn (Get $get): bool => filled($get('preview_url'))),
            FileUpload::make('image')
                ->label(__('catering.restaurant.menu.photo'))
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
        ];

        $fields[] = $this->choicesSection($selfKey);

        if ($withReprice) {
            $fields[] = Hidden::make('open_count')->default(0);
            $fields[] = Checkbox::make('reprice_open')
                ->label(__('catering.restaurant.menu.reprice'))
                ->helperText(fn (Get $get): string => __('catering.restaurant.menu.reprice_help').' '
                    .__('catering.restaurant.menu.open_affected', ['count' => (int) $get('open_count')]))
                ->visible(fn (Get $get): bool => (int) $get('open_count') > 0)
                ->default(false);
        }

        return $fields;
    }

    private function addAction(): Action
    {
        return Action::make('add')
            ->label(__('catering.restaurant.menu.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => Gate::allows('update', $this->restaurant()))
            ->modalHeading(__('catering.restaurant.menu.add'))
            ->schema($this->dishFields(false))
            ->modalWidth('3xl')
            ->action(function (array $data): void {
                $restaurant = $this->restaurant();
                $used = array_fill_keys(array_map(static fn (MenuOption $o): string => $o->key, $restaurant->menu), true);
                $name = trim((string) $data['name']);
                $option = new MenuOption(
                    MenuImport::uniqueKey($name, $used),
                    $name,
                    (int) FoodOrderForm::euroToCents((string) $data['price']),
                    MenuOption::cleanDescription($data['description'] ?? null),
                    is_string($data['image'] ?? null) ? $data['image'] : null,
                    self::choicesFromForm($data['choices'] ?? null),
                );

                $this->write($restaurant, [...$restaurant->menu, $option], false, 'added');
            });
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('catering.restaurant.menu.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconButton()
            ->authorize(fn (): bool => Gate::allows('update', $this->restaurant()))
            ->modalHeading(fn (array $record): string => $record['full_name'])
            ->modalDescription(fn (array $record): string => __('catering.restaurant.menu.key_help').' ('.$record['key'].')')
            ->fillForm(function (array $record): array {
                $option = collect($this->restaurant()->menu)->first(fn (MenuOption $o): bool => $o->key === $record['key']);

                return [
                    'name' => $option?->name,
                    'price' => $option === null ? null : number_format($option->priceCents / 100, 2, ',', ''),
                    'description' => $option?->description,
                    'image' => $option?->image,
                    'choices' => array_map(static fn (MenuChoice $c): array => [
                        ...$c->toArray(),
                        'source' => $c->usesCategories() ? 'categories' : 'options',
                    ], $option === null ? [] : $option->choices),
                    'preview_url' => $option === null ? null : DishImage::previewUrl($this->restaurant(), $option),
                    'reprice_open' => false,
                    'open_count' => $record['open'],
                ];
            })
            ->schema(fn (array $record): array => $this->dishFields(true, $record['key']))
            ->modalWidth('3xl')
            ->action(function (array $data, array $record): void {
                $restaurant = $this->restaurant();
                $menu = array_map(
                    fn (MenuOption $o): MenuOption => $o->key === $record['key']
                        ? new MenuOption(
                            $o->key,
                            trim((string) $data['name']),
                            (int) FoodOrderForm::euroToCents((string) $data['price']),
                            MenuOption::cleanDescription($data['description'] ?? null),
                            is_string($data['image'] ?? null) && $data['image'] !== '' ? $data['image'] : null,
                            self::choicesFromForm($data['choices'] ?? null),
                        )
                        : $o,
                    $restaurant->menu,
                );

                $this->write($restaurant, $menu, (bool) ($data['reprice_open'] ?? false), 'saved');
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('catering.restaurant.menu.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->iconButton()
            ->color('danger')
            ->authorize(fn (): bool => Gate::allows('update', $this->restaurant()))
            ->requiresConfirmation()
            ->disabled(fn (array $record): bool => $record['protected'] > 0)
            ->tooltip(fn (array $record): ?string => $record['protected'] > 0
                ? self::t('catering.restaurant.menu.protected', ['count' => $record['protected']])
                : null)
            ->action(function (array $record): void {
                $restaurant = $this->restaurant();
                $menu = array_values(array_filter($restaurant->menu, fn (MenuOption $o): bool => $o->key !== $record['key']));

                $this->write($restaurant, $menu, false, 'deleted');
            });
    }

    /**
     * The "Auswahl" section: selection groups that turn a dish into a set menu.
     */
    private function choicesSection(?string $selfKey): Section
    {
        $menu = fn (): array => $this->restaurant()->menu;

        return Section::make(__('catering.restaurant.menu.choices'))
            ->description(__('catering.restaurant.menu.choices_help'))
            ->collapsible()
            ->collapsed()
            ->schema([
                Repeater::make('choices')
                    ->hiddenLabel()
                    ->addActionLabel(__('catering.restaurant.menu.choices_add'))
                    ->defaultItems(0)
                    ->maxItems(MenuChoice::MAX_GROUPS)
                    ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) && $state['label'] !== '' ? $state['label'] : null)
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        TextInput::make('label')
                            ->label(__('catering.restaurant.menu.choice_label'))
                            ->helperText(__('catering.restaurant.menu.choice_label_help'))
                            ->required()
                            ->maxLength(MenuChoice::MAX_LABEL_LENGTH)
                            ->rules([FoodOrderForm::noNulBytes()]),
                        TextInput::make('count')
                            ->label(__('catering.restaurant.menu.choice_count'))
                            ->numeric()
                            ->integer()
                            ->required()
                            ->default(1)
                            ->minValue(MenuChoice::MIN_COUNT)
                            ->maxValue(MenuChoice::MAX_COUNT),
                        Radio::make('source')
                            ->label(__('catering.restaurant.menu.choice_source'))
                            ->options([
                                'categories' => __('catering.restaurant.menu.choice_source_categories'),
                                'options' => __('catering.restaurant.menu.choice_source_options'),
                            ])
                            ->default('categories')
                            ->live()
                            ->required(),
                        Toggle::make('allow_repeat')
                            ->label(__('catering.restaurant.menu.choice_repeat'))
                            ->helperText(__('catering.restaurant.menu.choice_repeat_help'))
                            ->default(true),
                        Select::make('categories')
                            ->label(__('catering.restaurant.menu.choice_categories'))
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => $this->categoryOptions($menu()))
                            ->visible(fn (Get $get): bool => ($get('source') ?? 'categories') === 'categories')
                            ->required(fn (Get $get): bool => ($get('source') ?? 'categories') === 'categories')
                            ->live()
                            ->columnSpanFull(),
                        Select::make('options')
                            ->label(__('catering.restaurant.menu.choice_options'))
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => $this->dishOptions($menu(), $selfKey))
                            ->visible(fn (Get $get): bool => $get('source') === 'options')
                            ->required(fn (Get $get): bool => $get('source') === 'options')
                            ->live()
                            ->columnSpanFull(),
                        Text::make(fn (Get $get): string => self::t('catering.restaurant.menu.choice_preview', [
                            'count' => count(MenuChoices::eligible(self::choiceFromState($get('label'), $get('count'), $get('source'), $get('categories'), $get('options'), $get('allow_repeat')), $menu(), (string) $selfKey)),
                        ]))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @param  list<MenuOption>  $menu
     * @return array<string, string>
     */
    private function categoryOptions(array $menu): array
    {
        $out = [];

        foreach ($menu as $option) {
            if (! $option->isSetMenu()) {
                $category = GroupOrder::split($option->name)[0];
                $out[$category] = $category;
            }
        }

        return $out;
    }

    /**
     * @param  list<MenuOption>  $menu
     * @return array<string, string>
     */
    private function dishOptions(array $menu, ?string $selfKey): array
    {
        $out = [];

        foreach ($menu as $option) {
            if (! $option->isSetMenu() && $option->key !== $selfKey) {
                $out[$option->key] = $option->name;
            }
        }

        return $out;
    }

    private static function choiceFromState(mixed $label, mixed $count, mixed $source, mixed $categories, mixed $options, mixed $repeat): MenuChoice
    {
        $strings = static fn (mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];

        return new MenuChoice(
            is_string($label) ? $label : '',
            is_numeric($count) ? (int) $count : 1,
            $source === 'options' ? [] : $strings($categories),
            $source === 'options' ? $strings($options) : [],
            $repeat !== false,
        );
    }

    /** @return list<MenuChoice> */
    private static function choicesFromForm(mixed $groups): array
    {
        $out = [];

        foreach (is_array($groups) ? $groups : [] as $group) {
            if (is_array($group)) {
                $out[] = self::choiceFromState(
                    trim((string) ($group['label'] ?? '')),
                    $group['count'] ?? 1,
                    $group['source'] ?? null,
                    $group['categories'] ?? null,
                    $group['options'] ?? null,
                    $group['allow_repeat'] ?? true,
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<MenuOption>  $menu
     */
    private function write(Restaurant $restaurant, array $menu, bool $reprice, string $done): void
    {
        Gate::authorize('update', $restaurant);

        try {
            $result = app(UpdateRestaurantMenu::class)->handle($restaurant, $menu, $reprice);
        } catch (ValidationException $e) {
            Notification::make()
                ->title(__('catering.restaurant.menu.invalid'))
                ->body(__('catering.restaurant.menu.invalid_help'))
                ->danger()
                ->send();

            return;
        } catch (MenuProtectedException $e) {
            Notification::make()
                ->title(__('catering.restaurant.menu.protected_many', ['list' => implode(', ', array_map(
                    static fn (string $key, int $n): string => "{$key} ({$n})",
                    array_keys($e->blocked),
                    array_values($e->blocked),
                ))]))
                ->danger()
                ->send();

            return;
        }

        $note = $reprice
            ? self::t('catering.restaurant.menu.repriced', ['count' => $result['repriced']])
            : ($result['open_affected'] > 0 ? self::t('catering.restaurant.menu.open_affected', ['count' => $result['open_affected']]) : null);

        Notification::make()
            ->title(__("catering.restaurant.menu.{$done}"))
            ->body($note)
            ->success()
            ->send();

        $this->resetTable();
    }
}
