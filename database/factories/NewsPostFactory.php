<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\News\Models\NewsPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NewsPost> */
class NewsPostFactory extends Factory
{
    protected $model = NewsPost::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'body' => fake()->paragraphs(3, true),
            'published_at' => null,
            'author_id' => User::factory(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'published_at' => now()->subHour(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'published_at' => null,
        ]);
    }

    public function scheduledInFuture(): static
    {
        return $this->state(fn (): array => [
            'published_at' => now()->addDay(),
        ]);
    }
}
