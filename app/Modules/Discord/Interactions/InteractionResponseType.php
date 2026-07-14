<?php

namespace App\Modules\Discord\Interactions;

/**
 * Discord interaction response callback types.
 *
 * @see https://docs.discord.com/developers/interactions/receiving-and-responding
 */
enum InteractionResponseType: int
{
    case Pong = 1;
    case ChannelMessageWithSource = 4;
    case DeferredChannelMessageWithSource = 5;
}
