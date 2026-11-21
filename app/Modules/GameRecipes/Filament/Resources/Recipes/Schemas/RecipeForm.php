<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Schemas;

use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\LauncherReview;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\PullRequestColor;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\RecipeStatusOptions;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Support\DisplayTimezone;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class RecipeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')
                ->label(__('game_recipes.fields.slug'))
                ->required()
                ->maxLength(120)
                ->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
                ->unique(ignoreRecord: true),
            TextInput::make('title')
                ->label(__('game_recipes.fields.title'))
                ->required()
                ->maxLength(200),
            TagsInput::make('aliases')
                ->label(__('game_recipes.fields.aliases'))
                ->placeholder('ut2004')
                ->helperText(__('game_recipes.fields.aliases_help')),
            TextInput::make('version')
                ->label(__('game_recipes.fields.version'))
                ->maxLength(60),
            Select::make('status')
                ->label(__('game_recipes.fields.status'))
                ->options(fn (): array => RecipeStatusOptions::for(auth()->user()))
                ->default(RecipeStatus::Draft->value)
                ->disabled(fn (): bool => ! RecipeStatusOptions::canReview(auth()->user()))
                ->required(),
            // The player-facing switch. A disabled field is not dehydrated, so
            // without the review ability a save keeps the stored value.
            Toggle::make('in_lineup')
                ->label(__('game_recipes.fields.in_lineup'))
                ->helperText(__('game_recipes.fields.in_lineup_help'))
                ->disabled(fn (): bool => ! RecipeStatusOptions::canReview(auth()->user()))
                ->columnSpanFull(),
            CheckboxList::make('platforms')
                ->label(__('game_recipes.fields.platforms'))
                ->options(['windows' => 'Windows', 'linux' => 'Linux', 'macos' => 'macOS'])
                ->columns(3),
            TextInput::make('summary')
                ->label(__('game_recipes.fields.summary'))
                ->maxLength(500),
            Textarea::make('description')
                ->label(__('game_recipes.fields.description'))
                ->rows(5),
            TextInput::make('homepage')
                ->label(__('game_recipes.fields.homepage'))
                ->url(),
            TextInput::make('license')
                ->label(__('game_recipes.fields.license')),
            Textarea::make('skill_markdown')
                ->label(__('game_recipes.fields.skill_markdown'))
                ->rows(12)
                ->helperText(__('game_recipes.fields.skill_help')),
            Repeater::make('requirements')
                ->label(__('game_recipes.fields.requirements'))
                ->schema([
                    Select::make('role')
                        ->label(__('game_recipes.fields.role'))
                        ->options(collect(RecipeFileRole::cases())->mapWithKeys(fn (RecipeFileRole $r) => [$r->value => $r->value])->all())
                        ->required(),
                    TextInput::make('label')
                        ->label(__('game_recipes.fields.file_label'))
                        ->required(),
                    TagsInput::make('match')
                        ->label(__('game_recipes.fields.match'))
                        ->placeholder('ut2004*.iso'),
                    TextInput::make('url')
                        ->label(__('game_recipes.fields.url'))
                        ->url(),
                    TextInput::make('sha256')
                        ->label(__('game_recipes.fields.sha256'))
                        ->maxLength(64),
                    TextInput::make('size_bytes')
                        ->label(__('game_recipes.fields.size_bytes'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->helperText(__('game_recipes.fields.size_bytes_help')),
                    TextInput::make('shared_file_id')
                        ->label(__('game_recipes.fields.shared_file'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->exists(SharedFile::class, 'id')
                        ->helperText(__('game_recipes.fields.shared_file_help')),
                    Toggle::make('required')
                        ->label(__('game_recipes.fields.required')),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->itemLabel(fn (array $state): ?string => isset($state['label']) ? $state['label'] : null),
            Repeater::make('steps')
                ->label(__('game_recipes.fields.steps'))
                ->schema([
                    TextInput::make('id')->label('id')->required(),
                    TextInput::make('title')->label(__('game_recipes.fields.step_title'))->required(),
                    // macos is a valid step OS in the schema and in the shipped
                    // recipes (UT2004, Minecraft); without the option the
                    // select rejected it on save ("Betriebssystem ist ungültig").
                    Select::make('os')->label(__('game_recipes.fields.os'))->options([
                        'any' => 'any',
                        'windows' => 'windows',
                        'linux' => 'linux',
                        'macos' => 'macos',
                    ])->default('any'),
                    Toggle::make('optional')->label(__('game_recipes.fields.optional')),
                    Textarea::make('detail')->label(__('game_recipes.fields.detail'))->rows(3),
                    Repeater::make('commands')
                        ->label(__('game_recipes.fields.commands'))
                        ->schema([
                            Select::make('shell')->options(['bash' => 'bash', 'powershell' => 'powershell', 'cmd' => 'cmd'])->default('bash'),
                            TextInput::make('run')->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(0),
                    // The step's checks have no editor; show them read-only so a
                    // reviewer sees everything the installer verifies. The entry
                    // has its own name on purpose: a component that owned the
                    // `verify` state path made the repeater drop the key on save.
                    TextEntry::make('verify_display')
                        ->hiddenLabel()
                        ->state(fn (Get $get): array => self::verifyLines($get('verify')))
                        ->listWithLineBreaks()
                        ->visible(fn (Get $get): bool => self::verifyLines($get('verify')) !== [])
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->itemLabel(fn (array $state): ?string => isset($state['title']) ? $state['title'] : null),
            // What the launcher and the start on the players' PCs run: launch
            // targets, play_url and the portable flag had no form field, so a
            // reviewer released commands they could not see.
            Section::make(__('game_recipes.launcher.section'))
                ->description(__('game_recipes.launcher.section_help'))
                ->columnSpanFull()
                ->visible(fn (?GameRecipe $record): bool => $record instanceof GameRecipe)
                ->schema(fn (?GameRecipe $record): array => self::launcherEntries($record)),
            // Where the recipe stands in the git catalogue. Read-only: the
            // proposal job and the status sync are the only writers.
            Section::make(__('recipe_pull_requests.section'))
                ->description(__('recipe_pull_requests.section_help'))
                ->columnSpanFull()
                ->visible(fn (?GameRecipe $record): bool => $record instanceof GameRecipe
                    && $record->pr_state instanceof PullRequestState)
                ->schema(fn (?GameRecipe $record): array => self::pullRequestEntries($record)),
        ]);
    }

    /**
     * One read-only line per step check (`file_exists`, `dir_exists` or `command`).
     *
     * @return list<string>
     */
    private static function verifyLines(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $lines = [];

        foreach ($state as $check) {
            if (is_array($check)) {
                $lines[] = self::verifyLine($check);
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $state */
    private static function verifyLine(array $state): string
    {
        $type = is_string($state['type'] ?? null) ? $state['type'] : '?';
        $path = is_string($state['path'] ?? null) ? $state['path'] : null;
        $command = is_string($state['command'] ?? null) ? $state['command'] : null;

        $line = $type.': '.($path ?? $command ?? '—');

        $os = is_string($state['os'] ?? null) && $state['os'] !== '' ? $state['os'] : null;

        return $os === null ? $line : $line.__('game_recipes.launcher.verify_os', ['os' => $os]);
    }

    /**
     * The read-only "Start und Launcher" body: the launcher decision and its
     * rule, the portable flag, play_url as plain text, every launch target with
     * its command exactly as stored, and the three-state override.
     *
     * @return list<Component>
     */
    private static function launcherEntries(?GameRecipe $record): array
    {
        if (! $record instanceof GameRecipe) {
            return [];
        }

        $offered = LauncherReview::offered($record);
        $portable = LauncherReview::portable($record);
        $declared = LauncherReview::portableDeclared($record);
        $playUrl = $record->playUrl();

        $entries = [
            Text::make($offered ? __('game_recipes.launcher.offered') : __('game_recipes.launcher.not_offered'))
                ->badge()
                ->color($offered ? 'success' : 'gray'),
            Text::make(__('game_recipes.launcher.reason', ['reason' => LauncherReview::reason($record)->label()])),
            // The stored flag, then (when it is set) whether the generic
            // installer's shape is complete: "portable: true" alone is not
            // enough, and the reviewer must see both.
            Text::make(__('game_recipes.launcher.portable', [
                'value' => $declared ? __('game_recipes.launcher.yes') : __('game_recipes.launcher.no'),
            ])),
        ];

        if ($declared) {
            $entries[] = $portable
                ? Text::make(__('game_recipes.launcher.portable_complete'))
                : Text::make(__('game_recipes.launcher.portable_missing', [
                    'missing' => implode(', ', LauncherReview::missingPortableConditions($record)),
                ]))->color('warning');
        }

        // Plain text on purpose: an unreviewed URL must not be one click away
        // from the reviewer.
        $entries[] = Text::make(__('game_recipes.launcher.play_url', ['value' => $playUrl ?? '—']));

        $entries[] = Text::make(__('game_recipes.launcher.targets'))->weight(FontWeight::SemiBold);

        $rows = LauncherReview::launchRows($record);

        if ($rows === []) {
            $entries[] = Text::make(__('game_recipes.launcher.no_targets'));
        }

        foreach ($rows as $index => $row) {
            $meta = $row['label'].' · '.$row['os'].' · '.($row['shell'] ?? __('game_recipes.launcher.no_shell'));

            if ($row['source'] !== 'launch_targets') {
                $meta .= ' · '.__('game_recipes.launcher.derived', ['source' => $row['source']]);
            }

            $entries[] = Text::make($meta)->weight(FontWeight::SemiBold);

            $command = $row['command'];
            $lines = $command === null
                ? [__('game_recipes.launcher.no_command')]
                : (preg_split('/\r\n|\n|\r/', $command) ?: [$command]);

            $entries[] = TextEntry::make('launch-command-'.$index)
                ->hiddenLabel()
                ->state($lines)
                ->listWithLineBreaks()
                ->fontFamily(FontFamily::Mono)
                ->wrap();

            if ($row['notes'] !== null) {
                $entries[] = Text::make($row['notes'])->size(TextSize::Small)->color('gray');
            }
        }

        $entries[] = self::launcherOverride();

        return $entries;
    }

    /**
     * The virtual three-state field. Its value is filled from the record's
     * metadata by the edit page (Filament does not apply `default()` on a plain
     * fill) and written back by the page's save: only
     * `metadata.launcher_supported` changes.
     */
    private static function launcherOverride(): Select
    {
        return Select::make('launcher_override')
            ->label(__('game_recipes.launcher.override'))
            ->helperText(__('game_recipes.launcher.override_help'))
            ->options([
                LauncherReview::OVERRIDE_AUTO => __('game_recipes.launcher.override_auto'),
                LauncherReview::OVERRIDE_ALWAYS => __('game_recipes.launcher.override_always'),
                LauncherReview::OVERRIDE_NEVER => __('game_recipes.launcher.override_never'),
            ])
            ->default(LauncherReview::OVERRIDE_AUTO)
            ->selectablePlaceholder(false)
            ->disabled(fn (): bool => ! RecipeStatusOptions::canReview(auth()->user()))
            ->columnSpanFull();
    }

    /** @return list<Component> */
    private static function pullRequestEntries(?GameRecipe $record): array
    {
        if (! $record instanceof GameRecipe || ! $record->pr_state instanceof PullRequestState) {
            return [];
        }

        $state = $record->pr_state;

        $entries = [
            Text::make($state->label())->badge()->color(PullRequestColor::for($state)),
        ];

        if ($state->existsRemotely() && is_string($record->pr_url) && $record->pr_url !== '') {
            $entries[] = TextEntry::make($record->pr_url)
                ->url($record->pr_url, shouldOpenInNewTab: true)
                ->color('primary');
        }

        if (in_array($state, [PullRequestState::Blocked, PullRequestState::Failed], true) && filled($record->pr_error)) {
            $entries[] = Text::make((string) $record->pr_error)->color('danger');
        }

        $entries[] = Text::make($record->pr_synced_at === null
            ? __('recipe_pull_requests.never_synced')
            : __('recipe_pull_requests.synced_at', [
                'time' => DisplayTimezone::local($record->pr_synced_at)->format('d.m.Y H:i'),
            ]));

        return $entries;
    }
}
