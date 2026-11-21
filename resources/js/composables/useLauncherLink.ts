import type { ComputedRef, Ref } from 'vue';
import { computed, getCurrentScope, onScopeDispose, ref } from 'vue';

/**
 * The one scheme this helper may ever open. `lanomat://` belongs to the
 * LANoMAT launcher, a desktop app; the browser hands the link to the operating
 * system and the page sees nothing of what happens next.
 */
export const LAUNCHER_SCHEME = 'lanomat://';

/** How long an attempt may stay unanswered before the page says so. */
export const LAUNCHER_ATTEMPT_TIMEOUT_MS = 2500;

/** How long "Launcher geöffnet" stays after a takeover before going quiet. */
export const LAUNCHER_OPENED_RESET_MS = 6000;

export type LauncherLinkState = 'idle' | 'trying' | 'opened' | 'unanswered';

export interface LauncherLinkAttemptOptions {
    /**
     * Start the navigation ourselves (the default). Pass `false` when a real
     * `<a href="lanomat://…">` already navigated on its own click, so the
     * scheme is fired exactly once.
     */
    navigate?: boolean;
    /** Override the wait for a takeover (tests, a slow machine). */
    timeoutMs?: number;
    /** Override how long the "opened" confirmation stays (tests). */
    openedResetMs?: number;
}

export interface LauncherLinkControls {
    /** `idle` → no attempt, `trying` → waiting, `opened`/`unanswered` → settled. */
    state: Ref<LauncherLinkState>;
    /** The URL of the last attempt, also kept after a dismissal. */
    lastUrl: Ref<string | null>;
    trying: ComputedRef<boolean>;
    /** Try to open a `lanomat://` URL. Anything else is ignored. */
    open: (url: string, options?: LauncherLinkAttemptOptions) => void;
    /** Run the last attempt again (this time navigating from code). */
    retry: () => void;
    /** Hide the notice; the last URL stays known for the next attempt. */
    dismiss: () => void;
}

/**
 * Open `lanomat://` actions and notice when nothing takes over.
 *
 * The honest limit, in one paragraph: a browser cannot ask whether a custom
 * scheme has a handler, so this is a heuristic, not an answer. We only know
 * that *something* took the focus — the launcher window, the browser's own
 * "open this application?" prompt, or any other app the OS brought forward.
 * That is why a takeover means "an attempt left the page", not "the install
 * started", and why the notice says what to do instead of claiming a failure.
 */
export function useLauncherLink(): LauncherLinkControls {
    const state = ref<LauncherLinkState>('idle');
    const lastUrl = ref<string | null>(null);
    const trying = computed(() => state.value === 'trying');

    let timer: ReturnType<typeof setTimeout> | null = null;
    let resetTimer: ReturnType<typeof setTimeout> | null = null;
    let detach: (() => void) | null = null;

    function clearAttempt(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }

        if (resetTimer !== null) {
            clearTimeout(resetTimer);
            resetTimer = null;
        }

        detach?.();
        detach = null;
    }

    function settle(
        next: LauncherLinkState,
        openedResetMs = LAUNCHER_OPENED_RESET_MS,
    ): void {
        clearAttempt();
        state.value = next;

        // A takeover ends in a short confirmation, then the block goes quiet
        // again: the page must not keep claiming something it cannot know.
        if (next === 'opened') {
            resetTimer = setTimeout(() => {
                resetTimer = null;
                state.value = 'idle';
            }, openedResetMs);
        }
    }

    function open(url: string, options: LauncherLinkAttemptOptions = {}): void {
        // SSR and plain imports never touch the DOM.
        if (typeof window === 'undefined' || typeof document === 'undefined') {
            return;
        }

        // This helper must never open anything but the launcher's own scheme.
        if (!url.startsWith(LAUNCHER_SCHEME)) {
            return;
        }

        clearAttempt();
        lastUrl.value = url;
        state.value = 'trying';

        const restoreAfterOpened =
            options.openedResetMs ?? LAUNCHER_OPENED_RESET_MS;

        // A takeover shows up as a lost focus or a hidden/unloaded page. Not
        // every operating system fires the same one, so we listen to all three.
        const takeover = (): void => settle('opened', restoreAfterOpened);
        const onVisibilityChange = (): void => {
            if (document.visibilityState === 'hidden') {
                settle('opened', restoreAfterOpened);
            }
        };

        window.addEventListener('blur', takeover);
        window.addEventListener('pagehide', takeover);
        document.addEventListener('visibilitychange', onVisibilityChange);
        detach = (): void => {
            window.removeEventListener('blur', takeover);
            window.removeEventListener('pagehide', takeover);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };

        // The assignment has to happen synchronously inside the click handler:
        // browsers only allow an external-protocol launch from a user gesture.
        if (options.navigate !== false) {
            window.location.href = url;
        }

        timer = setTimeout(() => {
            timer = null;

            // No event, but the page is not focused either: treat it as a
            // takeover, because the attempt did leave the page.
            if (
                typeof document.hasFocus === 'function' &&
                !document.hasFocus()
            ) {
                settle('opened', restoreAfterOpened);
            } else {
                settle('unanswered');
            }
        }, options.timeoutMs ?? LAUNCHER_ATTEMPT_TIMEOUT_MS);
    }

    function retry(): void {
        const url = lastUrl.value;

        if (url !== null) {
            // No anchor navigation this time — the retry is a click on a button.
            open(url);
        }
    }

    function dismiss(): void {
        clearAttempt();
        state.value = 'idle';
    }

    // A component that unmounts mid-attempt must not keep listeners or a timer.
    // (Outside a scope — a plain call in a test — there is nothing to clean up.)
    if (getCurrentScope() !== undefined) {
        onScopeDispose(clearAttempt);
    }

    return { state, lastUrl, trying, open, retry, dismiss };
}
