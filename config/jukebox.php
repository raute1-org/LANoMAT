<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Community skip-vote ratio
    |--------------------------------------------------------------------------
    |
    | Share (0..1) of an event's checked-in participants whose skip votes are
    | required to skip the currently playing track. The actual threshold is
    | max(3, ceil(checkedInCount * skip_ratio)) — see
    | App\Modules\Jukebox\Support\SkipThreshold — so small crowds still need
    | at least 3 votes. Orga/helper users can always skip/remove regardless
    | of votes (JukeboxPolicy::moderate).
    |
    */

    'skip_ratio' => env('JUKEBOX_SKIP_RATIO', 0.5),

];
