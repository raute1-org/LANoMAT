<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Assistant\Enums\AssistantActionStatus;
use App\Modules\Assistant\Models\AssistantAction;
use App\Modules\Assistant\Support\ArgumentsHash;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AssistantAction> */
class AssistantActionFactory extends Factory
{
    protected $model = AssistantAction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $arguments = ['name' => 'CS2 5v5'];

        return [
            'user_id' => User::factory(),
            'conversation_id' => (string) Str::uuid7(),
            'tool_call_id' => 'call_'.Str::lower(Str::random(12)),
            'tool' => 'tournament_create',
            'arguments' => $arguments,
            'arguments_hash' => ArgumentsHash::of('tournament_create', $arguments),
        ];
    }

    public function status(AssistantActionStatus $status): static
    {
        return $this->afterCreating(fn (AssistantAction $action) => $action->forceFill(['status' => $status])->save());
    }
}
