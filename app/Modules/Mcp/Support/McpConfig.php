<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

final class McpConfig
{
    public const API_VERSION = 1;

    public static function codeTtlSeconds(): int
    {
        return (int) config('mcp_agent.code_ttl', 600);
    }

    public static function pollInterval(): int
    {
        return max(1, (int) config('mcp_agent.poll_interval', 5));
    }

    public static function tokenTtlDays(): int
    {
        return max(1, (int) config('mcp_agent.token_ttl_days', 90));
    }
}
