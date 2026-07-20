<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxVote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JukeboxVote> */
class JukeboxVoteFactory extends Factory
{
    protected $model = JukeboxVote::class;

    public function definition(): array
    {
        return [
            'jukebox_item_id' => JukeboxItem::factory(),
            'user_id' => User::factory(),
        ];
    }
}
