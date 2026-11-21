<?php

/*
| Lighting safety limits. Strobe is LOCKED by default (photosensitivity /
| epilepsy): every strobe value is forced to 0 unless allowed. The admin page
| may override `allow_strobe` at runtime (lighting_settings); this value is
| the deployment default. `max_strobe` caps the strobe speed (bridge range
| 10..255). `game_mode_strobe_free` is an operator attestation that the
| bridge's Game Mode effect does not strobe (the bridge does not report it).
*/
return [
    'allow_strobe' => (bool) env('LIGHTING_ALLOW_STROBE', false),
    'max_strobe' => (int) env('LIGHTING_MAX_STROBE', 120),
    'game_mode_strobe_free' => (bool) env('LIGHTING_GAME_MODE_STROBE_FREE', false),
    'rate_limit_per_minute' => 60,
];
