import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

/** Live nav badges, shared Inertia prop `navSignals` (null for guests); defined with the Now page types. */
export type { NavSignals } from '@/types/now';

export type NavBadge = {
    text: string;
    variant?: 'live' | 'ok' | 'warn' | 'muted';
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    /** Explicit active override; wins over URL matching. */
    isActive?: boolean;
    /** Also highlight on nested paths (e.g. /teams/12 under /teams). */
    prefix?: boolean;
    /** Small status dot with an accessible text, e.g. a build exists for the visitor's system. */
    indicator?: string;
    /** Right-aligned live value (hidden when the sidebar is icon-collapsed). */
    badge?: NavBadge;
};
