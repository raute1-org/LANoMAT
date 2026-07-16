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
            // Event/* and Orga/* pages flash toasts (registration, seating,
            // check-in) but have no sidebar/header chrome, so they get the
            // minimal PublicShell instead of no layout — it exists solely to
            // mount <Toaster/> so those flashes have somewhere to render.
            case name.startsWith('Event/'):
            case name.startsWith('Orga/'):
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
