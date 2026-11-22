// node --test app/tests/link.test.mjs
// Connection-state rules without a DOM or a backend: local commands never prove the server is
// reachable, and the setup after connecting never runs twice at once and backs off on failure.
import assert from 'node:assert/strict';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const dir = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../ui/js',
);
const { ignoresAuthLoss, provesOnline, singleFlightRetry } = await import(
    path.join(dir, 'link.js')
);

test('local commands do not prove the server is reachable', () => {
    for (const cmd of [
        'get_status',
        'get_quiet',
        'get_room_view',
        'load_settings',
        'take_notification_target',
        // The store library never needs the server: its success must not flip the header.
        'library_local_available',
        'library_list',
        'library_scan',
        'library_launch',
        'library_open_store',
        'games_state',
    ]) {
        assert.equal(provesOnline(cmd), false, cmd);
    }
});

test('server commands prove the server is reachable', () => {
    for (const cmd of [
        'get_config',
        'current_event',
        'list_rooms',
        'now_feed',
    ]) {
        assert.equal(provesOnline(cmd), true, cmd);
    }
});

test('a lost session is ignored while signed out', () => {
    assert.equal(ignoresAuthLoss({ state: 'signed_out' }), true);
    for (const st of [
        null,
        undefined,
        { state: 'connected' },
        { state: 'expired' },
    ]) {
        assert.equal(ignoresAuthLoss(st), false);
    }
});

test('a running task is not started a second time', async () => {
    let runs = 0;
    let release;
    const gate = new Promise((r) => (release = r));
    const r = singleFlightRetry(async () => {
        runs += 1;
        await gate;
    });

    const a = r.kick();
    const b = r.kick();
    assert.equal(r.isRunning(), true);
    release();
    await Promise.all([a, b]);
    assert.equal(runs, 1);
    assert.equal(r.isRunning(), false);
});

test('retries back off exponentially up to the cap and reset starts over', () => {
    const timers = [];
    const r = singleFlightRetry(async () => {}, {
        baseMs: 1000,
        capMs: 5000,
        setTimer: (fn, ms) => {
            timers.push({ fn, ms });

            return timers.length;
        },
        clearTimer: () => {},
    });

    const delays = [];

    for (let i = 0; i < 5; i++) {
        delays.push(r.retryLater());
        timers.at(-1).fn(); // fire: clears the pending timer and runs the task
    }

    assert.deepEqual(delays, [1000, 2000, 4000, 5000, 5000]);

    r.reset();
    assert.equal(r.retryLater(), 1000);
});

test('only one retry is pending at a time', () => {
    let scheduled = 0;
    const r = singleFlightRetry(async () => {}, {
        setTimer: () => ++scheduled,
        clearTimer: () => {},
    });

    r.retryLater();
    assert.equal(r.retryLater(), undefined);
    assert.equal(scheduled, 1);
});
