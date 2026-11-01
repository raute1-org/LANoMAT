<?php

/*
 * Pinned third-party infrastructure that `lanomat:check-updates` compares with the latest upstream
 * release. Only a warning source: an outdated component never turns Preflight red.
 *
 * Per component: `image` is the Docker image whose pinned tag is read from the compose files below,
 * `repo` (optional) the GitHub `org/project` whose latest release is the reference. A component
 * without a `repo` is listed with its pinned version only (Docker Hub tag lookups are out of scope).
 */
return [
    // Compose files (relative to the project root) that are scanned for pinned image tags. Missing files are skipped.
    'compose_files' => [
        'compose.yml',
        'docker/file-host/compose.yml',
    ],

    // Hours a looked-up release stays cached; the scheduler refreshes more often than this.
    'cache_hours' => 12,

    'components' => [
        'mumble' => ['label' => 'Mumble', 'image' => 'mumblevoip/mumble-server', 'repo' => 'mumble-voip/mumble'],
        'livekit' => ['label' => 'LiveKit', 'image' => 'livekit/livekit-server', 'repo' => 'livekit/livekit'],
        'tusd' => ['label' => 'tusd', 'image' => 'tusproject/tusd', 'repo' => 'tus/tusd'],
        'traefik' => ['label' => 'Traefik', 'image' => 'traefik', 'repo' => 'traefik/traefik'],
        'nginx' => ['label' => 'nginx', 'image' => 'nginx', 'repo' => null],
        'postgres' => ['label' => 'PostgreSQL', 'image' => 'postgres', 'repo' => null],
        'redis' => ['label' => 'Redis', 'image' => 'redis', 'repo' => null],
    ],
];
