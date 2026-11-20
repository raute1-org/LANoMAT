export type DeepLinkAction = 'install' | 'launch';

export interface DeepLink {
    action: DeepLinkAction;
    slug: string;
    url: string;
}

/**
 * `lanomat://install/<slug>`, `lanomat://launch/<slug>` and `lanomat://play/<slug>`.
 * The play alias is what the web games page uses for its "Starten" button.
 */
export function parseDeepLink(input: string): DeepLink {
    let parsed: URL;

    try {
        parsed = new URL(input.trim());
    } catch {
        throw new Error(`Invalid LANoMAT link: ${input}`);
    }

    if (parsed.protocol !== 'lanomat:') {
        throw new Error(`Not a LANoMAT link (expected lanomat://…): ${input}`);
    }

    const parts = parsed.pathname.split('/').filter(Boolean).map((part) => decodeURIComponent(part));
    let action = parsed.hostname.toLowerCase();
    let slug = parts[0] ?? '';

    if (!action) {
        action = parts[0]?.toLowerCase() ?? '';
        slug = parts[1] ?? '';
    }

    if (action === 'play') {
        action = 'launch';
    }

    if (action !== 'install' && action !== 'launch') {
        throw new Error(`Unknown LANoMAT action "${action}" (use install, launch or play)`);
    }

    if (!slug || !/^[A-Za-z0-9._-]+$/.test(slug)) {
        throw new Error(`Invalid recipe slug in LANoMAT link: ${input}`);
    }

    return { action, slug, url: input };
}

export function deepLinkCommand(link: DeepLink): string {
    return `lanomat-agent ${link.action} ${link.slug}`;
}
