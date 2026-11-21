// Run with: node --test tests/js/useLauncherLink.test.ts
import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';
import type { EffectScope } from 'vue';
import { effectScope } from 'vue';
import type { LauncherLinkControls } from '../../resources/js/composables/useLauncherLink.ts';
import { useLauncherLink } from '../../resources/js/composables/useLauncherLink.ts';

type FakeWindow = EventTarget & { location: { href: string } };
type FakeDocument = EventTarget & {
    hasFocus: () => boolean;
    visibilityState: DocumentVisibilityState;
};

const INSTALL_URL = 'lanomat://install/unreal-tournament-2004';

let win: FakeWindow;
let doc: FakeDocument;
let focused = true;
let scope: EffectScope | null = null;

const globals = globalThis as unknown as {
    window?: unknown;
    document?: unknown;
};

function installBrowser(): void {
    focused = true;
    win = Object.assign(new EventTarget(), { location: { href: '' } });
    doc = Object.assign(new EventTarget(), {
        hasFocus: () => focused,
        visibilityState: 'visible' as DocumentVisibilityState,
    });
    globals.window = win;
    globals.document = doc;
}

function uninstallBrowser(): void {
    delete globals.window;
    delete globals.document;
}

/** One composable per test, inside a scope so disposal can be tested. */
function launcher(): LauncherLinkControls {
    scope = effectScope();
    const controls = scope.run(() => useLauncherLink());
    assert.ok(controls);

    return controls;
}

const wait = (ms: number): Promise<void> =>
    new Promise((resolve) => setTimeout(resolve, ms));

afterEach(() => {
    scope?.stop();
    scope = null;
    uninstallBrowser();
});

describe('useLauncherLink', () => {
    it('does nothing without a browser (SSR, plain import)', () => {
        uninstallBrowser();
        const link = launcher();

        assert.doesNotThrow(() => link.open(INSTALL_URL));
        assert.equal(link.state.value, 'idle');
        assert.equal(link.lastUrl.value, null);
    });

    it('refuses every scheme but lanomat://', () => {
        installBrowser();
        const link = launcher();

        link.open('https://lan.example/games');
        link.open('steam://connect/203.0.113.5:7777');

        assert.equal(link.state.value, 'idle');
        assert.equal(link.lastUrl.value, null);
        assert.equal(win.location.href, '');
    });

    it('starts the attempt synchronously and navigates', () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL);

        assert.equal(link.state.value, 'trying');
        assert.equal(link.trying.value, true);
        assert.equal(link.lastUrl.value, INSTALL_URL);
        // Firefox/Chrome only allow the scheme launch from a user gesture.
        assert.equal(win.location.href, INSTALL_URL);
    });

    it('leaves the navigation to the anchor when told to', () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL, { navigate: false });

        assert.equal(link.state.value, 'trying');
        assert.equal(win.location.href, '');
    });

    it('settles as opened when the page loses focus', () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL);
        win.dispatchEvent(new Event('blur'));

        assert.equal(link.state.value, 'opened');
    });

    it('settles as opened when the page is hidden or unloaded', () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL);
        doc.visibilityState = 'hidden';
        doc.dispatchEvent(new Event('visibilitychange'));

        assert.equal(link.state.value, 'opened');
    });

    it('settles as opened on pagehide', () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL);
        win.dispatchEvent(new Event('pagehide'));

        assert.equal(link.state.value, 'opened');
    });

    it('stays unanswered when nothing takes focus', async () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL, { timeoutMs: 20 });
        await wait(60);

        assert.equal(link.state.value, 'unanswered');
        assert.equal(link.trying.value, false);
        assert.equal(link.lastUrl.value, INSTALL_URL);
    });

    it('treats an unfocused page as a takeover at the timeout', async () => {
        installBrowser();
        const link = launcher();

        focused = false;
        link.open(INSTALL_URL, { timeoutMs: 20 });
        await wait(60);

        assert.equal(link.state.value, 'opened');
    });

    it('removes the listeners once an attempt settled', async () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL, { timeoutMs: 20 });
        await wait(60);
        win.dispatchEvent(new Event('blur'));

        assert.equal(link.state.value, 'unanswered');
    });

    it('retries the last attempt and navigates itself', () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL, { navigate: false });
        assert.equal(win.location.href, '');

        link.retry();

        assert.equal(link.state.value, 'trying');
        assert.equal(win.location.href, INSTALL_URL);
    });

    it('dismisses to idle but keeps the last URL', () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL, { timeoutMs: 20 });
        link.dismiss();

        assert.equal(link.state.value, 'idle');
        assert.equal(link.lastUrl.value, INSTALL_URL);
    });

    it('stops waiting and removes its listeners when disposed', async () => {
        installBrowser();
        const link = launcher();

        link.open(INSTALL_URL, { timeoutMs: 20 });
        scope?.stop();
        await wait(60);
        win.dispatchEvent(new Event('blur'));

        assert.equal(link.state.value, 'trying');
    });
});
