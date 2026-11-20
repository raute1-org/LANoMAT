<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Console;

use App\Models\User;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use App\Modules\Mcp\Support\McpConfig;
use Illuminate\Console\Command;

/**
 * Mint a token without the browser for headless setups (CI, a kiosk, an orga
 * laptop). The plain token is printed once and never stored.
 */
class IssueMcpTokenCommand extends Command
{
    protected $signature = 'lanomat:mcp-token
        {user : User id, email or Discord id}
        {--name=CLI : Token name shown under /mcp/connect}';

    protected $description = 'Issue a LANoMAT MCP bearer token for a user (printed once).';

    public function handle(): int
    {
        $argument = $this->argument('user');
        $needle = is_string($argument) ? $argument : '';

        $user = User::query()
            ->where('id', ctype_digit($needle) ? (int) $needle : 0)
            ->orWhere('email', $needle)
            ->orWhere('discord_id', $needle)
            ->first();

        if (! $user instanceof User) {
            $this->error('No user matches that id, email or Discord id.');

            return self::FAILURE;
        }

        $plain = McpCodes::bearerToken();
        $ttl = McpConfig::tokenTtlDays();

        (new McpToken)->forceFill([
            'user_id' => $user->getKey(),
            'name' => is_string($name = $this->option('name')) ? $name : 'CLI',
            'token_hash' => McpCodes::hash($plain),
            'expires_at' => now()->addDays($ttl),
        ])->save();

        $this->info("Token for {$user->name} (valid {$ttl} days):");
        $this->line($plain);
        $this->newLine();
        $this->line('Configure pi with:');
        $this->line('  pi mcp add lanomat --url '.route('mcp.endpoint').' --bearer-token-env-var LANOMAT_TOKEN');
        $this->line('  export LANOMAT_TOKEN='.$plain);

        return self::SUCCESS;
    }
}
