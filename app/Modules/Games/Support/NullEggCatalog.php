<?php

declare(strict_types=1);

namespace App\Modules\Games\Support;

use App\Modules\Games\Contracts\EggCatalog;

final class NullEggCatalog implements EggCatalog
{
    public function options(): ?array
    {
        return null;
    }
}
