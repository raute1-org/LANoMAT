// Screens that exist only when the Rust side has their commands and the server runs their
// module: Freunde, Profil and the "Personen" view (where is everybody). Nothing is faked: an
// entry stays hidden unless its command answers.
import { gamesTabWanted } from './games-model.js';
import { moduleOn, setTabGate } from './nav.js';
import { invoke, isUnknownCommand, normalizeError, state } from './util.js';

export const optional = {
    friends: false,
    profile: false,
    people: false,
    games: false,
};

// A command the Rust side does not have rejects with "unknown command"; the server answers
// not_found while the module is off. Any other answer (even an error) means the screen is wanted.
async function probe(cmd, args) {
    try {
        await invoke(cmd, args);

        return true;
    } catch (e) {
        const err = normalizeError(e);

        return !isUnknownCommand(err) && err.kind !== 'not_found';
    }
}

// The Spiele tab: see `gamesTabWanted`. A missing command keeps it hidden, like any optional
// tab; a failing `games_state` does not (the local list can still work).
async function probeGames() {
    try {
        return gamesTabWanted(await invoke('games_state'));
    } catch (e) {
        const err = normalizeError(e);

        return !isUnknownCommand(err) && err.kind !== 'not_found';
    }
}

export async function probeOptional() {
    optional.games = await probeGames();
    setTabGate('spiele', optional.games);
    optional.friends = moduleOn('friends') && (await probe('list_friends'));
    optional.profile = moduleOn('identity') && (await probe('get_profile'));
    optional.people =
        moduleOn('presence') &&
        Boolean(state.event) &&
        (await probe('list_people', {
            event: state.event.slug,
            perPage: 1,
        }));
}

export const peopleOn = () => optional.people && Boolean(state.event);
