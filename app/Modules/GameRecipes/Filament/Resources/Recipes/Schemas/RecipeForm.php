<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Schemas;

use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\RecipeStatusOptions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

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
                    Select::make('os')->label(__('game_recipes.fields.os'))->options(['any' => 'any', 'windows' => 'windows', 'linux' => 'linux'])->default('any'),
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
                ])
                ->columns(2)
                ->defaultItems(0)
                ->itemLabel(fn (array $state): ?string => isset($state['title']) ? $state['title'] : null),
        ]);
    }
}
