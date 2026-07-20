<?php

return [
    // The bracket overlay reuses the existing tournaments/gameservers label
    // bundles verbatim (see OverlayController::bracket()) since
    // BracketView.vue is unchanged.

    // SceneScoreboard.vue's exact label keys (Task 10.7's scoreboard
    // overlay) — copies the beamer's `infoscreen.screen.scoreboard_*`
    // strings verbatim into their own scoped bundle rather than handing the
    // overlay the beamer's entire (bracket/tombola/gong/...) label bag it
    // does not need.
    'scoreboard' => [
        'scoreboard_title' => 'Live-Scoreboard',
        'scoreboard_round' => 'Runde :number',
    ],
];
