<?php

use App\Modules\Mcp\Http\AuthenticateMcpToken;
use App\Modules\Mcp\Server\LanomatServer;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Laravel\Mcp\Facades\Mcp;

/*
| LANoMAT MCP server (streamable HTTP). Loaded by laravel/mcp from this file.
| Bearer tokens only: a piped `pi` session, Claude Desktop, Cursor or the
| bundled `lanomat-agent` never use the browser session. The device flow in
| routes/mcp.php mints the token; /mcp/connect approves it.
*/
if (app(Modules::class)->routable(Module::Mcp)) {
    Mcp::web('/mcp', LanomatServer::class)
        ->middleware([AuthenticateMcpToken::class, 'throttle:mcp-api'])
        ->name('mcp.endpoint');
}
