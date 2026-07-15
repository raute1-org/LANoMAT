<?php

namespace App\Modules\Discord\Interactions;

class CommandDefinitions
{
    /**
     * Global slash command definitions registered via bulk overwrite
     * (`PUT /applications/{id}/commands`). Names here must match the keys
     * in {@see CommandRouter}'s command map.
     *
     * @see https://docs.discord.com/developers/interactions/application-commands
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'name' => 'tournament',
                'description' => __('discord.commands.tournament.description'),
                'options' => [
                    [
                        'type' => 1, // SUB_COMMAND
                        'name' => 'list',
                        'description' => __('discord.commands.tournament.list.description'),
                    ],
                    [
                        'type' => 1,
                        'name' => 'info',
                        'description' => __('discord.commands.tournament.info.description'),
                        'options' => [
                            [
                                'type' => 4, // INTEGER
                                'name' => 'id',
                                'description' => __('discord.commands.tournament.info.id_option'),
                                'required' => true,
                            ],
                        ],
                    ],
                    [
                        'type' => 1,
                        'name' => 'checkin',
                        'description' => __('discord.commands.tournament.checkin.description'),
                        'options' => [
                            [
                                'type' => 4,
                                'name' => 'id',
                                'description' => __('discord.commands.tournament.checkin.id_option'),
                                'required' => true,
                            ],
                        ],
                    ],
                    [
                        'type' => 1,
                        'name' => 'bracket',
                        'description' => __('discord.commands.tournament.bracket.description'),
                        'options' => [
                            [
                                'type' => 4,
                                'name' => 'id',
                                'description' => __('discord.commands.tournament.bracket.id_option'),
                                'required' => true,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'help',
                'description' => __('discord.commands.help.description'),
            ],
            [
                'name' => 'schedule',
                'description' => __('discord.commands.schedule.description'),
            ],
            [
                'name' => 'lfg',
                'description' => __('discord.commands.lfg.description'),
                'options' => [
                    [
                        'type' => 1, // SUB_COMMAND
                        'name' => 'list',
                        'description' => __('discord.commands.lfg.list.description'),
                    ],
                    [
                        'type' => 1,
                        'name' => 'create',
                        'description' => __('discord.commands.lfg.create.description'),
                        'options' => [
                            [
                                'type' => 3, // STRING
                                'name' => 'title',
                                'description' => __('discord.commands.lfg.create.title_option'),
                                'required' => true,
                            ],
                            [
                                'type' => 3,
                                'name' => 'game',
                                'description' => __('discord.commands.lfg.create.game_option'),
                                'required' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
