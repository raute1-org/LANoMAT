<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Idempotent local test user; promote to admin with:
        // php artisan lanomat:install --admin-discord-id=100000000000000001
        User::firstOrCreate(
            ['discord_id' => '100000000000000001'],
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'role' => Role::Participant,
            ],
        );
    }
}
