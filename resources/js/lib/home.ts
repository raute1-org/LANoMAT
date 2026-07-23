import { index as eventsIndex, show as eventsShow } from '@/routes/events';
import type { EventSummary } from '@/types/events';

/**
 * The app's home target: the current LAN's page when one is active, else the
 * events overview. Used by the sidebar header and the public header so both
 * agree on where "home"/the logo points.
 */
export function homeHref(currentEvent: EventSummary | null) {
    return currentEvent ? eventsShow(currentEvent.slug) : eventsIndex();
}
