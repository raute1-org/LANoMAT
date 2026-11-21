<?php

namespace App\Modules\Catering\Enums;

/** How an agent-supplied menu is applied to the food orders of an event. */
enum MenuImportMode: string
{
    /** A new draft round with this menu. */
    case Create = 'create';
    /** The menu of an existing round is replaced (only while nobody ordered from it). */
    case Replace = 'replace';
    /** New dishes are appended; existing dishes (and their keys) stay untouched. */
    case Merge = 'merge';
}
