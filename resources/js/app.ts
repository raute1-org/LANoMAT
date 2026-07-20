import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PublicShell from '@/layouts/PublicShell.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

configureEcho({
    broadcaster: 'reverb',
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Event/*, Orga/* and Jukebox/* pages flash toasts (registration,
            // seating, check-in, queue/vote/skip mutations) but have no
            // sidebar/header chrome, so they get the minimal PublicShell
            // instead of no layout — it exists solely to mount <Toaster/> so
            // those flashes have somewhere to render. Jukebox is also
            // reachable by unauthenticated guests (read-only board), and the
            // default AppLayout's sidebar unconditionally renders NavUser
            // assuming a non-null auth.user, so guests must not get it.
            case name.startsWith('Event/'):
            case name.startsWith('Orga/'):
            case name.startsWith('Jukebox/'):
                return PublicShell;
            // Prefix match strips the app layout from EVERY page under this
            // directory. Future authenticated pages must not be placed here
            // unless they also want no layout — otherwise set an explicit
            // layout via `defineOptions({ layout: ... })`.
            case name.startsWith('Profile/'):
                return null;
            // The public beamer screen renders a bare full-viewport shell —
            // no app navigation/header, no toaster (it is unattended and
            // never triggers flashes).
            case name.startsWith('Screen/'):
                return null;
            // Public, no-auth OBS browser-source overlays (bracket,
            // scoreboard, ...) — bare full-viewport shell like Screen/, but
            // with a transparent background (see OverlayFrame.vue) so OBS
            // composites the page over gameplay footage.
            case name.startsWith('Overlay/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
