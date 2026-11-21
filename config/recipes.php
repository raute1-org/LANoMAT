<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recipe sources
    |--------------------------------------------------------------------------
    |
    | Where `lanomat:recipes:sync` reads from when no source is given. The
    | bundled recipes ship with the app; the NAS share is the live catalogue
    | the user maintains (smb://nas.local/games-recipes in the LAN).
    | Credentials are optional: a guest-readable share needs none.
    |
    */

    'source' => env('RECIPES_SOURCE', base_path('resources/recipes')),

    'smb' => [
        'user' => env('RECIPES_SMB_USER'),
        'password' => env('RECIPES_SMB_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LAN file preference
    |--------------------------------------------------------------------------
    |
    | The installer downloads from the LANoMAT file host first. Only when a
    | required file has no LAN copy does it consider the recipe's external
    | URL, and then only with `--allow-external`.
    |
    */

    'prefer_lan' => (bool) env('RECIPES_PREFER_LAN', true),

    /*
    |--------------------------------------------------------------------------
    | Contributions
    |--------------------------------------------------------------------------
    |
    | How many draft recipes one participant may have waiting for review at
    | the same time (shared games, MCP and REST contributions together). The
    | orga is not limited.
    |
    */

    'max_drafts_per_user' => (int) env('RECIPES_MAX_DRAFTS_PER_USER', 20),

    /*
    |--------------------------------------------------------------------------
    | Pull requests into the git catalogue
    |--------------------------------------------------------------------------
    |
    | A recipe a participant shares is a database draft. With this block
    | enabled LANoMAT also opens a pull request that adds the recipe to the
    | repository's catalogue (recipe.yaml and SKILL.md under `path`), so the
    | next deployment ships it for everyone. Only recipe text travels; game
    | files never do.
    |
    | Off by default. It needs a repository ("owner/name") and a fine-grained
    | token with "Contents" and "Pull requests" read and write on exactly that
    | repository. `trigger` decides when the pull request is opened:
    | "publish" (the default: only after the orga published the recipe, so the
    | repository only sees reviewed text) or "contribute" (as soon as a
    | participant shares or edits a draft — the documented opt-in for an
    | installation that wants the pull request immediately). `attribution` adds
    | the contributor's display name to the pull request; it is off because the
    | repository may be public.
    |
    | Two bounds keep one participant and this installation from flooding the
    | repository: `per_user_hourly` caps automatic proposals per contributor
    | per hour, `max_open` caps this installation's open pull requests (an
    | update of a recipe that already has one does not count). The orga's
    | manual `lanomat:recipes:propose` and the panel's "propose now" are not
    | limited.
    |
    */

    'pull_requests' => [
        'enabled' => (bool) env('RECIPES_PR_ENABLED', false),
        'repository' => env('RECIPES_PR_REPOSITORY'),
        'token' => env('RECIPES_PR_TOKEN'),
        'base' => env('RECIPES_PR_BASE', 'main'),
        'branch_prefix' => env('RECIPES_PR_BRANCH_PREFIX', 'recipes/'),
        'path' => env('RECIPES_PR_PATH', 'resources/recipes'),
        'trigger' => env('RECIPES_PR_TRIGGER', 'publish'),
        'per_user_hourly' => (int) env('RECIPES_PR_PER_USER_HOURLY', 6),
        'max_open' => (int) env('RECIPES_PR_MAX_OPEN', 30),
        'attribution' => (bool) env('RECIPES_PR_ATTRIBUTION', false),
        'api_url' => env('RECIPES_PR_API_URL', 'https://api.github.com'),
    ],

];
