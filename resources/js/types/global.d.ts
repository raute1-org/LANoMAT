import type { Auth } from '@/types/auth';
import type { EventSummary } from '@/types/events';
import type { NotificationItem } from '@/types/notifications';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            currentEvent: EventSummary | null;
            notificationLabels: Record<string, string>;
            unreadNotifications?: NotificationItem[];
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}

// Reverb connection config injected inline by app.blade.php (from server
// config at request time) and read in app.ts to configure Echo at runtime.
declare global {
    interface Window {
        __reverb?: {
            key: string | null;
            host: string | null;
            port: number | string | null;
            scheme: string | null;
        };
    }
}
