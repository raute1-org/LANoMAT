<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxSkipVote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JukeboxSkipVote> */
class JukeboxSkipVoteFactory extends Factory
{
    protected $model = JukeboxSkipVote::class;

    public function definition(): array
    {
        return [
            'jukebox_item_id' => JukeboxItem::factory(),
            'user_id' => User::factory(),
        ];
    }
}
