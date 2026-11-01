// Static check of the UI modules, run with plain node (no build): every `S.a.b` string path used
// by a module exists in strings.js, calls are functions, and each tab has its help sheet.
// Usage: node app/tests/check-ui.mjs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../ui/js',
);
const { S } = await import(path.join(dir, 'strings.js'));
let bad = 0;

for (const f of fs
    .readdirSync(dir)
    .filter((x) => x.endsWith('.js') && x !== 'strings.js')) {
    const src = fs.readFileSync(path.join(dir, f), 'utf8');

    for (const m of src.matchAll(
        /(?<![A-Za-z_.])S\.([a-zA-Z_]+(?:\.[a-zA-Z_0-9]+)*)(\[|\()?/g,
    )) {
        let v = S;
        let ok = true;

        for (const k of m[1].split('.')) {
            if (v === null || v === undefined || !(k in Object(v))) {
                ok = false;
                break;
            }

            v = v[k];
        }

        if (!ok) {
            console.log('MISSING', f, m[1]);
            bad++;
        } else if (m[2] === '(' && typeof v !== 'function') {
            console.log('NOT A FUNCTION', f, m[1]);
            bad++;
        } else if (!m[2] && typeof v === 'function') {
            console.log('FUNCTION USED AS A VALUE', f, m[1]);
            bad++;
        }
    }
}

for (const t of ['jetzt', 'sprache', 'spielen', 'essen', 'mehr']) {
    if (!S.help[t] || !S.tabs[t]) {
        console.log('missing tab strings', t);
        bad++;
    }
}

// The update notice decisions (app/ui/js/update-policy.js): fail open, dismiss per version, and the
// focus re-check after more than ten minutes away.
const policy = await import(path.join(dir, 'update-policy.js'));
const none = new Set();
const checks = [
    ['no status shows nothing', policy.noticeFor(null, none) === null],
    [
        'an error (no status) shows nothing',
        policy.noticeFor(undefined, none) === null,
    ],
    [
        'up to date shows nothing',
        policy.noticeFor({ status: 'up_to_date' }, none) === null,
    ],
    [
        'an unknown state shows nothing',
        policy.noticeFor({ status: 'something_new' }, none) === null,
    ],
    [
        'an offered update names its version',
        JSON.stringify(
            policy.noticeFor(
                { status: 'update_available', latest: 'm13-13-g1' },
                none,
            ),
        ) === JSON.stringify({ kind: 'available', latest: 'm13-13-g1' }),
    ],
    [
        'Später hides the offer for that version only',
        policy.noticeFor(
            { status: 'update_available', latest: 'm13-13-g1' },
            new Set(['m13-13-g1']),
        ) === null &&
            policy.noticeFor(
                { status: 'update_available', latest: 'm13-14-g2' },
                new Set(['m13-13-g1']),
            ) !== null,
    ],
    [
        'an incompatible client is never dismissed by Später',
        policy.noticeFor(
            { status: 'incompatible', min: 'm13-13-g1' },
            new Set(['m13-13-g1']),
        ).kind === 'incompatible',
    ],
    [
        'the manual check names a dismissed update in words',
        policy.manualLine(
            { status: 'update_available', latest: 'm13-13-g1' },
            new Set(['m13-13-g1']),
        ).kind === 'still',
    ],
    [
        'the manual check says up to date',
        policy.manualLine({ status: 'up_to_date' }, none).kind === 'upToDate',
    ],
    [
        'the focus re-check needs more than ten minutes away',
        !policy.shouldCheckAfterFocus(0, 10 * 60 * 1000),
    ],
    [
        'more than ten minutes away re-checks',
        policy.shouldCheckAfterFocus(0, 10 * 60 * 1000 + 1),
    ],
    [
        'no blur recorded, no re-check',
        !policy.shouldCheckAfterFocus(null, 99999999),
    ],
];

for (const [name, ok] of checks) {
    if (!ok) {
        console.log('FAIL update policy:', name);
        bad++;
    }
}

console.log(bad ? `FAIL (${bad})` : 'ui strings ok');
process.exit(bad ? 1 : 0);
