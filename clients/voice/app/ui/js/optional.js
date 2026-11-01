// Screens that exist only when the Rust side has their commands and the server runs their
// module: Freunde, Profil and the "Personen" view (where is everybody). Nothing is faked: an
// entry stays hidden unless its command answers.
import { moduleOn } from './nav.js';
import { invoke, isUnknownCommand, normalizeError, state } from './util.js';

export const optional = { friends: false, profile: false, people: false };

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

export async function probeOptional() {
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
