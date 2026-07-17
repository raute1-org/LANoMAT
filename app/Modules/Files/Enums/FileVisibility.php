<?php

namespace App\Modules\Files\Enums;

enum FileVisibility: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('files.visibility.'.$this->value);
    }
}
