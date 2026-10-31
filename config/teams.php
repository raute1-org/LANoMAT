<?php

return [
    // Team logos are re-encoded without metadata (App\Support\Images\SanitizeImage).
    'logo_max_edge' => (int) env('TEAMS_LOGO_MAX_EDGE', 512),
    // Decoder safety cap on declared width x height (decompression bombs).
    'logo_max_pixels' => (int) env('TEAMS_LOGO_MAX_PIXELS', 16_000_000),
];
