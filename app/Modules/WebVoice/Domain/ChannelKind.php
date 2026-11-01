<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Domain;

enum ChannelKind: string
{
    case General = 'general';
    case Match = 'match';
    /** A team's standing room for the whole tournament (team tournaments only). */
    case Team = 'team';
}
