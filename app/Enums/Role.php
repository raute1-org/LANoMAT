<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Orga = 'orga';
    case Participant = 'participant';
}
