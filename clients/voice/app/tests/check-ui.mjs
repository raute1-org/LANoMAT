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

console.log(bad ? `FAIL (${bad})` : 'ui strings ok');
process.exit(bad ? 1 : 0);
