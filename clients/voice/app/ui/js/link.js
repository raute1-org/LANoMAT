// Connection state without a DOM: which command results say anything about the server, and a
// single-flight retry with backoff for the setup after connecting. Plain functions so
// app/tests/link.test.mjs can drive them with node.

// Commands that never reach the server. Their success proves nothing about the connection, so
// they must not flip the app to "online" (that made the header flicker between "Verbunden" and
// the offline banner while the server was unreachable).
export const LOCAL_COMMANDS = new Set([
    'get_update_status',
    'library_local_available',
    'library_list',
    'library_scan',
    'library_launch',
    'library_open_store',
    'get_status',
    'connect_cancel',
    'set_talking',
    'set_muted',
    'get_room_view',
    'list_devices',
    'load_settings',
    'save_settings',
    'get_environment',
    'open_url',
    'open_client_download',
    'quit',
    'list_transfers',
    'transfer_pause',
    'transfer_resume',
    'transfer_cancel',
    'transfer_forget',
    'set_quiet',
    'get_quiet',
    'audio_check_start',
    'audio_check_stop',
    'play_test_tone',
    'take_notification_target',
    'chat_unsubscribe',
    'open_join_url',
    'open_link_url',
    'copy_text',
    'pick_files',
    'pick_directory',
    'get_download_dir',
    'games_state',
]);

// After "Abmelden" there is no session left to lose: a request that was still on its way (a poll
// of the Jetzt feed) fails with an auth error, and that must not throw the signed-out library
// back to the connect screen with "Sitzung abgelaufen". The same holds in the signed-out library
// mode ("Meine Spiele ohne Anmeldung"), also for an expired token whose credentials are still
// stored: the server part of the library may answer 401 there, and that is not news.
export const ignoresAuthLoss = (status, offlineMode = false) =>
    offlineMode === true || (Boolean(status) && status.state === 'signed_out');

// Where the setup after a reconnect lands. Every retry of the setup ends by showing a screen; a
// retry must not pull the person away from the tab they opened meanwhile. While setup still fails
// (`setupDone` false) the open screen is left alone; once it works the same screen is shown again
// so its data reloads. No tab open yet: Jetzt. (`go` falls back to Jetzt for a hidden tab.)
export const resumeTab = (current, setupDone) => ({
    tab: current || 'jetzt',
    force: Boolean(current) && setupDone === true,
});

// True when a successful answer to `cmd` shows the server is reachable.
export const provesOnline = (cmd) => !LOCAL_COMMANDS.has(cmd);

// Runs `task` at most once at a time. `kick()` starts it now unless it is already running;
// `retryLater()` schedules the next run with exponential backoff (base, 2x, 4x … up to cap);
// `reset()` clears the backoff after a success. Timers are injectable for tests.
export function singleFlightRetry(
    task,
    {
        baseMs = 2000,
        capMs = 60000,
        setTimer = setTimeout,
        clearTimer = clearTimeout,
    } = {},
) {
    let running = null;
    let timer = null;
    let failures = 0;

    async function kick() {
        if (running) {
            return running;
        }

        clearTimer(timer);
        timer = null;
        running = (async () => {
            try {
                return await task();
            } finally {
                running = null;
            }
        })();

        return running;
    }

    function retryLater() {
        if (timer) {
            return;
        }

        const delay = Math.min(capMs, baseMs * 2 ** failures);
        failures += 1;
        timer = setTimer(() => {
            timer = null;
            void kick();
        }, delay);

        return delay;
    }

    function reset() {
        failures = 0;
        clearTimer(timer);
        timer = null;
    }

    return {
        kick,
        retryLater,
        reset,
        isRunning: () => Boolean(running),
    };
}
