import { usePage } from '@inertiajs/vue3';

const FALLBACK_TIMEZONE = 'Europe/Berlin';

/**
 * The ONE display timezone (server `config('lanomat.timezone')`, shared as the
 * `displayTimezone` Inertia prop). Events are German, so dates render in that
 * zone for everyone, independent of the visitor's browser or the UTC storage.
 */
export function displayTimezone(): string {
    const shared = usePage().props.displayTimezone;

    return typeof shared === 'string' && shared !== ''
        ? shared
        : FALLBACK_TIMEZONE;
}

/** Format an ISO instant in the display timezone (German locale by default). */
export function formatInstant(
    iso: string | Date,
    options: Intl.DateTimeFormatOptions,
    locale = 'de-DE',
): string {
    return new Intl.DateTimeFormat(locale, {
        ...options,
        timeZone: displayTimezone(),
    }).format(new Date(iso));
}

/** `YYYY-MM-DD` of the instant's calendar day in the display timezone (grouping key). */
export function dayKey(iso: string | Date): string {
    return formatInstant(
        iso,
        {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        },
        'sv-SE',
    );
}
