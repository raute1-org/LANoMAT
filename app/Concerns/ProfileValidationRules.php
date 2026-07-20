<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'bio' => $this->bioRules(),
            'steam_url' => $this->steamUrlRules(),
            'stream_url' => $this->streamUrlRules(),
            'profile_color' => $this->profileColorRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate the user bio.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function bioRules(): array
    {
        return ['nullable', 'string', 'max:1000'];
    }

    /**
     * Get the validation rules used to validate the user's Steam profile URL.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function steamUrlRules(): array
    {
        return ['nullable', 'url', 'starts_with:https://steamcommunity.com/'];
    }

    /**
     * Get the validation rules used to validate the user's stream URL. Any
     * https URL is permitted — unlike the Steam URL, we don't restrict this
     * to a single platform (Twitch, YouTube, Kick, …).
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function streamUrlRules(): array
    {
        return ['nullable', 'url', 'max:255'];
    }

    /**
     * Get the validation rules used to validate the user's profile color.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function profileColorRules(): array
    {
        return ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
    }
}
