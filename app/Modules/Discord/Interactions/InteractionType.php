<?php

namespace App\Modules\Discord\Interactions;

/**
 * Discord interaction request types.
 *
 * @see https://docs.discord.com/developers/interactions/receiving-and-responding
 */
enum InteractionType: int
{
    case Ping = 1;
    case ApplicationCommand = 2;
    case MessageComponent = 3;
    case ApplicationCommandAutocomplete = 4;
    case ModalSubmit = 5;
}
