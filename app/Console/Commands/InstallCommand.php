<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'lanomat:install {--admin-discord-id=} {--admin-name=Admin}';

    protected $description = 'Run migrations and create the initial admin user';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        $discordId = $this->option('admin-discord-id');

        if (is_string($discordId) && $discordId !== '') {
            $user = User::firstOrNew(['discord_id' => $discordId]);
            $user->name ??= (string) $this->option('admin-name');
            $user->role = Role::Admin;
            $user->save();

            $this->info("Admin ready: {$user->name} ({$discordId})");
        }

        return self::SUCCESS;
    }
}
