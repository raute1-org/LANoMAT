#!/usr/bin/env python3
"""Regenerate docs/brand/tokens.json and tokens.css from resources/css/app.css.

Run from the repo root: python3 docs/brand/export-tokens.py
"""
import json
import re

css = open('resources/css/app.css').read()


def block(sel):
    m = re.search(r'^' + re.escape(sel) + r' \{(.*?)^\}', css, re.S | re.M)
    return m.group(1)


def decls(b):
    b = re.sub(r'/\*.*?\*/', '', b, flags=re.S)
    return re.findall(r'--([\w-]+):\s*([^;]+);', b)


root, dark = decls(block(':root')), decls(block('.dark'))
is_ref = lambda v: v.strip().startswith('var(')
prim = {k: v.strip() for k, v in root if not is_ref(v) and k != 'radius'}
radius = [v.strip() for k, v in root if k == 'radius'][0]
light = {k: v.strip() for k, v in root if is_ref(v)}
dk = {k: v.strip() for k, v in dark}


def role(v):
    name = re.fullmatch(r'var\(--([\w-]+)\)', v).group(1)
    return {'ref': name, 'value': prim[name]}


out = {
    '$comment': 'Generated from resources/css/app.css (source of truth). Do not edit by hand; see docs/brand/README.md.',
    'primitives': prim,
    'radius': radius,
    'fonts': {
        'sans': "'Space Grotesk', ui-sans-serif, system-ui, sans-serif",
        'mono': "'JetBrains Mono', ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace",
    },
    'roles': {
        'light': {k: role(v) for k, v in light.items()},
        'dark': {k: role(v) for k, v in dk.items()},
    },
}
with open('docs/brand/tokens.json', 'w') as f:
    json.dump(out, f, indent=2)
    f.write('\n')


def fmt(items):
    return ''.join(f'    --{k}: {v.strip()};\n' for k, v in items)


with open('docs/brand/tokens.css', 'w') as f:
    f.write('/* Generated from resources/css/app.css (source of truth) - see docs/brand/README.md. */\n')
    f.write(':root {\n' + fmt([(k, v) for k, v in root if k in prim]) + f'    --radius: {radius};\n')
    f.write(fmt([(k, v) for k, v in root if k in light]) + '}\n\n.dark {\n' + fmt(dark) + '}\n')
