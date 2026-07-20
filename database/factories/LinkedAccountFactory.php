<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LinkedAccount> */
class LinkedAccountFactory extends Factory
{
    protected $model = LinkedAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => LinkedAccountProvider::Twitch,
            'provider_user_id' => fake()->unique()->numerify('##########'),
            'nickname' => fake()->unique()->userName(),
            'scopes' => null,
            'meta' => null,
        ];
    }
}
