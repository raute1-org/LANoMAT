// "Spiel teilen": share a game with the event.
//
// The launcher cannot pack or upload an archive itself: `<paired server>/games/share`
// in the browser is where the file goes and the description is written. After the
// orga has checked the archive, the game appears in this catalogue like any other.
// The page lives on the paired server (same base as the server browser and the
// game pages), so a LAN-only installation never points at a public host, and the
// entry is not offered while unpaired or without a valid http(s) base.
import { state } from './state.js';
import { SHARE_PATH, S } from './strings.js';
import {
    $,
    announce,
    call,
    safeHttpUrl,
    serverBase,
    setHidden,
    setText,
} from './util.js';

// Every entry into the share page: the library header, the start page's main
// actions and the two empty states. Hints are the visible one-liners.
const BUTTONS = [
    'library-share',
    'start-share',
    'library-empty-share',
    'library-installed-empty-share',
];
const HINTS = ['library-share-hint', 'start-share-hint'];

/** The paired server's share page, or null without a valid http(s) base. */
export function shareUrl() {
    const base = serverBase(state.url || state.status?.server?.url);

    return base ? safeHttpUrl(`${base}${SHARE_PATH}`) : null;
}

/** Whether this window may offer "Spiel teilen" at all. */
export function isOffered() {
    return Boolean(state.status?.paired) && Boolean(shareUrl());
}

/**
 * Show or hide every share entry from the pairing status. Called whenever the
 * status changes (boot, connect, library refresh, logout).
 */
export function sync() {
    const offered = isOffered();

    for (const id of BUTTONS) {
        setHidden(id, !offered);
    }

    for (const id of HINTS) {
        setHidden(id, !offered);
    }
}

export function init() {
    for (const id of BUTTONS) {
        const button = $(id);

        if (button) {
            button.title = S.library.shareHint;
            button.addEventListener('click', openShare);
        }
    }

    // One hint, one source: the visible one-liner next to the entries.
    for (const id of HINTS) {
        setText(id, S.library.shareHint);
    }

    sync();
}

/** Open the share page through the shell instead of navigating the window. */
export async function openShare() {
    const url = shareUrl();

    if (!isOffered() || !url) {
        announce(S.library.shareUnavailable);

        return;
    }

    const result = await call('open_url', { url });

    announce(result.ok ? S.library.shareOpened : S.library.shareError);
}
