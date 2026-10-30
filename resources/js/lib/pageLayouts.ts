/**
 * The single, explicit layout decision for every Inertia page.
 *
 * Keys are page names (`Dashboard`) or directory prefixes ending in `/`
 * (`Teams/` covers every page below resources/js/pages/Teams). The value is the
 * shell family — see docs/design.md "Page anatomy & layout rules".
 *
 *   app      sidebar shell (AppLayout) — every authenticated participant page
 *   settings AppLayout + settings sub-navigation
 *   auth     AuthLayout (login / confirm / 2FA)
 *   profile  AppLayout for logged-in viewers, bare for guests (public profile)
 *   bare     no chrome: guest landing, beamer screens, OBS overlays
 *
 * tests/Architecture/PageLayoutsTest.php fails when a page under
 * resources/js/pages has no entry here (or an entry matches no page), so a new
 * page can never silently fall into the wrong shell.
 */
export type PageLayoutKind = 'app' | 'settings' | 'auth' | 'profile' | 'bare';

export const pageLayouts: Record<string, PageLayoutKind> = {
    Welcome: 'bare',
    'Screen/': 'bare',
    'Overlay/': 'bare',
    'auth/': 'auth',
    'settings/': 'settings',
    'Profile/': 'profile',
    Dashboard: 'app',
    'Catering/': 'app',
    'Event/': 'app',
    'Files/': 'app',
    'Friends/': 'app',
    'Gallery/': 'app',
    'Jukebox/': 'app',
    'Lfg/': 'app',
    'Orga/': 'app',
    'Polls/': 'app',
    'Presence/': 'app',
    'Recap/': 'app',
    'Schedule/': 'app',
    'Servers/': 'app',
    'Stats/': 'app',
    'Teams/': 'app',
    'Tournaments/': 'app',
    'Voice/': 'app',
};

export function layoutKindFor(name: string): PageLayoutKind {
    const kind =
        pageLayouts[name] ??
        Object.entries(pageLayouts).find(
            ([key]) => key.endsWith('/') && name.startsWith(key),
        )?.[1];

    if (kind === undefined) {
        throw new Error(
            `Page "${name}" has no layout decision — add it to resources/js/lib/pageLayouts.ts`,
        );
    }

    return kind;
}
