<?php

namespace App\Providers\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * Central navigation-group registry for the admin panel sidebar, so every
 * module's Filament resources read as one coherent product instead of a
 * loose pile of per-module groups. The case order below is the sidebar
 * order.
 *
 * Deliberately does NOT implement `HasIcon`: Filament forbids a navigation
 * group from having both a group-level icon and per-item icons on its
 * resources (raises an exception at render time), and every resource here
 * already carries its own meaningful `$navigationIcon` — the finer-grained,
 * more informative choice — so the group stays label-only.
 *
 * This lives alongside AdminPanelProvider (panel-level configuration), not
 * inside a module — it deliberately crosses module boundaries, which is fine
 * for presentation-only grouping (no module reaches into another module's
 * data or logic through this enum).
 */
enum AdminNavigationGroup implements HasLabel
{
    case Event;
    case AnmeldungUndSitzplan;
    case TurniereUndTeams;
    case Programm;
    case Infoscreen;

    public function getLabel(): string
    {
        return match ($this) {
            self::Event => 'Event',
            self::AnmeldungUndSitzplan => 'Anmeldung & Sitzplan',
            self::TurniereUndTeams => 'Turniere & Teams',
            self::Programm => 'Programm, Catering & Voting',
            self::Infoscreen => 'Infoscreen',
        };
    }
}
