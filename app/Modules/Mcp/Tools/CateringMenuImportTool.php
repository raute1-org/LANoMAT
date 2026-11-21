<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('catering_menu_import')]
#[Description('Orga/admin: writes a structured menu as a new draft food round (mode=create), replaces the menu of a round nobody ordered from (replace) or appends new dishes (merge). Atomic; refuses closed rounds and replace on rounds with orders. Run catering_menu_preview first and let the human confirm. The round stays a draft: opening it is a separate human step. Dish photos are not imported: they are added in the admin panel or with the artisan command catering:import-dish-media.')]
#[IsIdempotent]
class CateringMenuImportTool extends CateringMenuWriteTool
{
    protected function apply(): bool
    {
        return true;
    }
}
