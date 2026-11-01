// The decisions of the update notice, without DOM or Tauri, so `app/tests/check-ui.mjs` can test
// them with plain node. The Rust side decides the status (`get_update_status`, docs/commands.md).

export const CHECK_EVERY_MS = 30 * 60 * 1000;
export const FOCUS_RECHECK_AFTER_MS = 10 * 60 * 1000;

// After the window was away for more than ten minutes, a check on its return.
export function shouldCheckAfterFocus(awaySince, now) {
    return awaySince !== null && now - awaySince > FOCUS_RECHECK_AFTER_MS;
}

// What to show for a status. Fail open: no status, an error, up to date or an unknown state
// means nothing. An incompatible client always gets its notice; an offered update can be
// dismissed for its version (`dismissed` is a Set of versions, kept in memory only).
export function noticeFor(status, dismissed) {
    if (!status) {
        return null;
    }

    if (status.status === 'incompatible' && status.min) {
        return { kind: 'incompatible', min: status.min };
    }

    if (
        status.status === 'update_available' &&
        status.latest &&
        !dismissed.has(status.latest)
    ) {
        return { kind: 'available', latest: status.latest };
    }

    return null;
}

// The same, for the manual check: an offered update that was dismissed is named in words only.
export function manualLine(status, dismissed) {
    if (
        status &&
        status.status === 'update_available' &&
        dismissed.has(status.latest)
    ) {
        return { kind: 'still', latest: status.latest };
    }

    if (status && status.status === 'up_to_date') {
        return { kind: 'upToDate' };
    }

    return { kind: 'none' };
}
