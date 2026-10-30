import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import type { Auth } from '@/types/auth';

// Reverb connection data comes from the server at runtime via the
// `window.__reverb` global set inline in app.blade.php (from server config).
// This is deliberately NOT import.meta.env.VITE_REVERB_*: Vite inlines those at
// build time, so the Docker image would bake .env.example's values and a real
// deploy .env could never change where the browser connects. The inline blade
// script runs before this deferred module, so the global is always present.
const reverb = window.__reverb;
const reverbPort =
    reverb?.port !== null && reverb?.port !== undefined
        ? Number(reverb.port)
        : undefined;

configureEcho({
    broadcaster: 'reverb',
    key: reverb?.key ?? undefined,
    wsHost: reverb?.host ?? undefined,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: reverb?.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name, page) => {
        switch (true) {
            // Public profile (/users/{user}) is the one participant page that
            // guests may still open: logged-in users get the full app shell,
            // guests stay bare (the sidebar's NavUser assumes a non-null user).
            case name.startsWith('Profile/'):
                return (page.props as { auth?: Auth }).auth?.user
                    ? AppLayout
                    : null;
            // The public beamer screen renders a bare full-viewport shell —
            // no app navigation/header, no toaster (it is unattended and
            // never triggers flashes).
            case name.startsWith('Screen/'):
                return null;
            // The guest landing is a bare, event-free shell (no sidebar).
            case name === 'Welcome':
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
