<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('catering_menu_import')]
#[Description('Orga/admin: writes a structured menu to a RESTAURANT (menus belong to the restaurant, not to a round): mode=create makes a new restaurant (name required), replace swaps its menu (dishes that were already ordered must keep their keys, so take them from catering_menu_get), merge appends new dishes. Atomic. Run catering_menu_preview first and let the human confirm. It never creates or opens a round: use catering_round_create for that. Dish photos are not imported: they are added in the admin panel or with the artisan command catering:import-dish-media. event_slug + order_id still work (deprecated) and resolve to the restaurant of that round.')]
#[IsIdempotent]
class CateringMenuImportTool extends CateringMenuWriteTool
{
    protected function apply(): bool
    {
        return true;
    }
}
