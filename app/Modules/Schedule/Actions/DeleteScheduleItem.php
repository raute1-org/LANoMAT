<?php

namespace App\Modules\Schedule\Actions;

use App\Modules\Schedule\Models\ScheduleItem;

class DeleteScheduleItem
{
    public function handle(ScheduleItem $item): void
    {
        $item->delete();
    }
}
