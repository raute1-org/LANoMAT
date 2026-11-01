// Avatars: the server sends the path of a small square copy (never a Discord address) and the
// `get_avatar` command turns it into a data: URI (fetched once with the login, then kept in the
// app's cache). The monogram is always there first; the picture is laid over it when it arrives.
// Loading is lazy (only rows that come into view, a few requests at a time) and never blocks a
// screen: any failure just leaves the monogram. `invoke` is used directly on purpose: a missing
// avatar must not flip the online state or end the session like a failed list call would.
import { S } from './strings.js';
import { el, invoke } from './util.js';

const MAX_PARALLEL = 3;
const known = new Map(); // path -> Promise<string | null>
const waiting = [];
let running = 0;

function pump() {
    while (running < MAX_PARALLEL && waiting.length) {
        const job = waiting.shift();

        running++;
        invoke('get_avatar', { path: job.path })
            .then((uri) => job.done(typeof uri === 'string' ? uri : null))
            .catch(() => {
                known.delete(job.path); // worth another try on the next screen load
                job.done(null);
            })
            .finally(() => {
                running--;
                pump();
            });
    }
}

// One request per path however many rows show the person.
export function avatarSource(path) {
    if (!known.has(path)) {
        known.set(
            path,
            new Promise((done) => {
                waiting.push({ path, done });
                pump();
            }),
        );
    }

    return known.get(path);
}

function show(node, name, uri) {
    if (!uri || !node.isConnected) {
        return;
    }

    node.append(
        el('img', {
            class: 'avatar-img',
            src: uri,
            alt: S.avatar.alt(name),
            decoding: 'async',
            draggable: 'false',
        }),
    );
}

// Lays the person's picture over the monogram `node` once the node is on screen.
export function attachAvatar(node, path, name) {
    if (typeof path !== 'string' || !path) {
        return;
    }

    const load = () => avatarSource(path).then((uri) => show(node, name, uri));

    if (typeof IntersectionObserver !== 'function') {
        load();

        return;
    }

    const watcher = new IntersectionObserver(
        (entries) => {
            if (entries.some((e) => e.isIntersecting)) {
                watcher.disconnect();
                load();
            }
        },
        { rootMargin: '120px' },
    );

    watcher.observe(node);
}
