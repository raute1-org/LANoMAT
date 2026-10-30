# LANoMAT brand identity

Canonical identity layer for anything branded as LANoMAT: UI, emails, Discord messages, beamer screens, OBS overlays, posters. It **formalises what already exists** (the "Signalpult" design system); it invents nothing. Where the repo has no evidence, the file says **GAP** instead of guessing.

## Which file for which task

| Task | Read |
|------|------|
| Who/what is LANoMAT, name spelling, personality | [identity.md](identity.md) |
| Colours, type, radius, motion, accessibility | [visual.md](visual.md) |
| Logo, favicon, fonts, what files exist | [logo-and-assets.md](logo-and-assets.md) |
| Writing any copy (German UI, Discord, notifications) | [voice.md](voice.md) |
| Rules per surface (app, admin, beamer, overlay, Discord, mail, print) | [surfaces.md](surfaces.md) |
| Machine-readable tokens | [tokens.json](tokens.json), [tokens.css](tokens.css) |
| Where every claim comes from, open questions | [sources.md](sources.md) |
| Implementing UI in the codebase | [../design.md](../design.md) (the implementation rulebook) |

`docs/design.md` stays the UI implementation rulebook; `docs/brand/` is the identity layer on top. Overlap is linked, not duplicated.

## Source of truth

`resources/css/app.css` is the single source of truth for token values. `tokens.json` / `tokens.css` are generated exports and must never be edited by hand. Regenerate after any change to `app.css`:

```bash
python3 docs/brand/export-tokens.py   # run from the repo root
```

The tables in `visual.md` copy values at time of writing; if they disagree with `app.css`, `app.css` wins.

## Status

| Area | Status |
|------|--------|
| Colour, type, shape, motion, a11y | ready (grounded in `app.css` + `design.md`) |
| Name usage, personality | ready |
| Voice (German UI, Discord, notifications) | ready, with two noted inconsistencies |
| Surfaces: web app, beamer, overlay | ready |
| Surfaces: admin panel (Filament), emails | partial |
| Logo | GAP: mark is the stock Laravel starter-kit glyph, no LANoMAT-specific logo, no usage rules |
| Favicon / apple-touch icon | GAP: SVG recoloured amber, ICO + PNG are stock Laravel red |
| OG / social image, print | GAP: nothing exists |

Full list of open questions: [sources.md](sources.md#open-gaps-and-questions-for-the-owner).
