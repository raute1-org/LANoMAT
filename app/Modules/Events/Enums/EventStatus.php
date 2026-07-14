<?php

namespace App\Modules\Events\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Announced = 'announced';
    case Registration = 'registration';
    case Live = 'live';
    case Finished = 'finished';
    case Archived = 'archived';
}
