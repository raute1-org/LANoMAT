<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('catering_menu_preview')]
#[Description('Dry run of catering_menu_import (orga/admin): validates a structured menu you read from a PDF, web page or photo against the data model and returns errors, warnings, the resulting menu and what would change. Writes nothing. Always call this first and show the result to the human.')]
#[IsReadOnly]
class CateringMenuPreviewTool extends CateringMenuWriteTool
{
    protected function apply(): bool
    {
        return false;
    }
}
