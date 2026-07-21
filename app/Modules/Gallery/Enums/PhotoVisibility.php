<?php

namespace App\Modules\Gallery\Enums;

enum PhotoVisibility: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('gallery.visibility.'.$this->value);
    }
}
