// Connection state without a DOM: which command results say anything about the server, and a
// single-flight retry with backoff for the setup after connecting. Plain functions so
// app/tests/link.test.mjs can drive them with node.

// Commands that never reach the server. Their success proves nothing about the connection, so
// they must not flip the app to "online" (that made the header flicker between "Verbunden" and
// the offline banner while the server was unreachable).
export const LOCAL_COMMANDS = new Set([
    'get_update_status',
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
