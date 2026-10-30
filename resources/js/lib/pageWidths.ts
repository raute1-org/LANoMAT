/**
 * Named content-width tiers for authenticated pages (one source of truth, used
 * by components/PageContainer.vue). Containers are LEFT-aligned inside the main
 * area so every page starts at the same x-position as the breadcrumb bar.
 *
 *   narrow   max-w-xl   forms, single-column flows, scanner pages
 *   default  max-w-3xl  reading, lists, overviews
 *   wide     max-w-6xl  dashboards, tables, card grids, brackets
 *   full     no cap     seat map / bracket canvases that scroll themselves
 *
 * See docs/design.md "Page anatomy & layout rules".
 */
export type PageWidth = 'narrow' | 'default' | 'wide' | 'full';

export const pageWidthClasses: Record<PageWidth, string> = {
    narrow: 'max-w-xl',
    default: 'max-w-3xl',
    wide: 'max-w-6xl',
    full: 'max-w-none',
};
