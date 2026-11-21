export type ServerLiveVariant = 'ok' | 'warn' | 'down';

/**
 * `ServerLinkStatus` → `LiveIndicator` variant (ready ok, provisioning/pending/
 * not_responding warn, everything else down). Shared by the server overview
 * rows and the Jetzt permanent-server panel.
 */
const variants: Record<string, ServerLiveVariant> = {
    ready: 'ok',
    provisioning: 'warn',
    pending: 'warn',
    failed: 'down',
    stopped: 'down',
    not_responding: 'warn',
    install_failed: 'down',
    offline: 'down',
};

export function serverLiveVariant(status: string): ServerLiveVariant {
    return variants[status] ?? 'down';
}
