<?php

namespace App\Modules\Lfg\Actions;

use App\Modules\Lfg\Models\LfgPost;

class DeleteLfgPost
{
    public function handle(LfgPost $post): void
    {
        $post->delete();
    }
}
