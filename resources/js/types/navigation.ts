import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
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
};
