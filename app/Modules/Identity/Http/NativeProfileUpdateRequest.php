<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Http\Requests\Settings\ProfileUpdateRequest;

/**
 * The browser's profile rules, unchanged. PUT is the browser's full form (name and email
 * required); PATCH only requires them when sent, so a client can change a single field. Fields
 * outside the rules (role, discord_id, avatar, ...) never reach the action.
 */
class NativeProfileUpdateRequest extends ProfileUpdateRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        if ($this->isMethod('PATCH')) {
            foreach (['name', 'email'] as $field) {
                $rules[$field] = array_map(fn (mixed $rule): mixed => $rule === 'required' ? 'sometimes' : $rule, (array) $rules[$field]);
            }
        }

        return $rules;
    }
}
