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
        $user = User::firstOrNew(['discord_id' => '100000000000000001']);
        $user->name = 'Test User';
        $user->email = 'test@example.com';
        $user->role = Role::Participant;
        $user->save();

        $this->call(GamesSeeder::class);
    }
}
