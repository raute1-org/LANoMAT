<?php

use App\Modules\Hosts\SshRemoteExecutor;

// SshRemoteExecutor talks real SSH via phpseclib3 and is never instantiated
// in tests (see FakeRemoteExecutor for the double used everywhere else) —
// exercising a real connection is out of scope. These tests cover the two
// pure, extracted decisions instead:
//   1. fingerprintMatches(): the pinning comparison used by
//      assertFingerprintPinned() in connect()/upload()/probe(), BEFORE
//      login() — the pre-login ordering itself is verified by code
//      inspection (see task-2-report.md), not a runtime test.
//   2. deriveFingerprint(): the SHA256 fingerprint derivation, verified here
//      against real `ssh-keygen -lf` output for a freshly generated key.

it('matches when the actual fingerprint equals the pinned one', function () {
    expect(SshRemoteExecutor::fingerprintMatches('SHA256:abc123', 'SHA256:abc123'))->toBeTrue();
});

it('does not match when the actual fingerprint differs from the pinned one', function () {
    expect(SshRemoteExecutor::fingerprintMatches('SHA256:abc123', 'SHA256:different'))->toBeFalse();
});

it('does not match when the actual fingerprint is null (host key unreadable)', function () {
    expect(SshRemoteExecutor::fingerprintMatches('SHA256:abc123', null))->toBeFalse();
});

it('derives the exact OpenSSH SHA256 fingerprint format for a known ed25519 key', function () {
    // Generated with `ssh-keygen -t ed25519` and cross-checked with
    // `ssh-keygen -lf` against the resulting .pub file:
    //
    //   $ ssh-keygen -lf test_key.pub
    //   256 SHA256:odnnxdXc4FhC2XHZ9rkcdRxxx7ZuQ7dl03lbS/sKFq0 no comment (ED25519)
    //
    // This proves deriveFingerprint() (and therefore fingerprintOf(), which
    // wraps it around SSH2::getServerPublicHostKey()'s return value) matches
    // OpenSSH's own fingerprint format byte-for-byte, not just "looks right".
    $formattedHostKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFsn8RSD8m7yRKGQ+AXnejpoB5phBpBWsglEOlcYWcAi';

    expect(SshRemoteExecutor::deriveFingerprint($formattedHostKey))
        ->toBe('SHA256:odnnxdXc4FhC2XHZ9rkcdRxxx7ZuQ7dl03lbS/sKFq0');
});
