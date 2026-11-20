<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent pairing (device flow)
    |--------------------------------------------------------------------------
    |
    | Same shape as the native voice client: a short code the user approves in
    | the browser, then a long-lived bearer token. Tokens slide on use; revoke
    | them under /mcp/connect.
    |
    */

    'code_ttl' => (int) env('MCP_CODE_TTL', 600),
    'poll_interval' => (int) env('MCP_POLL_INTERVAL', 5),
    'token_ttl_days' => (int) env('MCP_TOKEN_TTL_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Default model for the pi agent
    |--------------------------------------------------------------------------
    |
    | The server does not call a model itself, but advertises the model the
    | bundled installer configures, so docs and `lanomat-agent doctor` can
    | agree on one default. Venice AI, end-to-end encrypted DeepSeek V4.1
    | Flash 1017 Abliterated.
    |
    */

    'default_model' => env('MCP_DEFAULT_MODEL', 'venice/e2ee-deepseek-v4-1-flash-1017-abliterated'),

];
