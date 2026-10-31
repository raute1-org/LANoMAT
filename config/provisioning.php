<?php

/*
| Provisioning (optional module `provisioning`, docs/provisioning.md).
|
| No credentials live here: hypervisors are database rows with an encrypted
| API token (`provisioning:probe-hypervisor`, RegisterHypervisor). This file
| only holds behaviour: timeouts, polling and the safety-net timing.
*/
return [
    // `proxmox` talks to the real Proxmox VE API; `fake` binds the in-memory
    // FakeHypervisorClient (local development without a hypervisor).
    'driver' => env('PROVISIONING_DRIVER', 'proxmox'),

    'proxmox' => [
        'timeout' => (int) env('PROVISIONING_PROXMOX_TIMEOUT', 20),
        'connect_timeout' => (int) env('PROVISIONING_PROXMOX_CONNECT_TIMEOUT', 5),
        // Max seconds to wait for one Proxmox task (UPID): VM create, image download, ...
        'task_timeout' => (int) env('PROVISIONING_TASK_TIMEOUT', 900),
        'task_poll_seconds' => (int) env('PROVISIONING_TASK_POLL_SECONDS', 3),
    ],

    'steps' => [
        // Per-step wall-clock budget of the queued job (seconds).
        'timeout' => (int) env('PROVISIONING_STEP_TIMEOUT', 1200),
        'ssh_wait_seconds' => (int) env('PROVISIONING_SSH_WAIT_SECONDS', 300),
        'ssh_poll_seconds' => (int) env('PROVISIONING_SSH_POLL_SECONDS', 5),
    ],

    // Egress isolation lock-out safety net: a systemd timer deletes the
    // nftables table after this many minutes unless the automation cancels it.
    'safety_net_minutes' => (int) env('PROVISIONING_SAFETY_NET_MINUTES', 6),

    // Longest redacted log kept per step (characters).
    'log_limit' => 8000,
];
