/**
 * Whether a `lanomat://join/<slug>` link names the server it joins.
 *
 * The launcher can be told the server two ways: `?address=<host>:<port>` (the
 * game takes it on its command line) or `?url=<join url>` (the launcher opens
 * it after the start). A link without either still installs and starts the
 * game, but the player then picks the server inside the game. The website
 * must not call that "joining": the action is named and explained by what the
 * link really does.
 */
export function joinLinkNamesServer(url: string | null | undefined): boolean {
    if (typeof url !== 'string') {
        return false;
    }

    const query = url.indexOf('?');

    if (query === -1) {
        return false;
    }

    const params = new URLSearchParams(url.slice(query + 1));

    return (
        (params.get('address') ?? '') !== '' || (params.get('url') ?? '') !== ''
    );
}
