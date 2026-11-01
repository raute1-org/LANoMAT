export type DesktopClientOffer = {
    /** Download page (`/talk/client`). */
    url: string;
    /** Version of the published client, if a manifest exists. */
    version: string | null;
    os: 'linux' | 'windows' | 'macos' | null;
    /** A matching GUI build for this visitor's desktop OS exists. */
    available: boolean;
};
