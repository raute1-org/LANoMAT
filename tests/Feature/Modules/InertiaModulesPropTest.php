<?php

it('shares the enabled module keys with every inertia page', function () {
    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('modules', fn ($modules) => collect($modules)->contains('tournaments')
            && collect($modules)->contains('jukebox'))); // suite runs under MODULES_PROFILE=full
});
