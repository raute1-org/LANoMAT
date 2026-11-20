---
title: Contributing
description: Add a recipe, contribute an ISO, and get both past the schema and the skill review.
sidebar_icon: git-pull-request
---

A recipe is a small, reviewable artefact. You do not need PHP to add one: two files, a schema check
and a pull request. The same pull request can add the game's disc image to the LAN file host, but
never to git.

## 1. Copy the shape

```text
resources/recipes/<slug>/
├── recipe.yaml
└── SKILL.md
```

Use `unreal-tournament-2004` as the reference. The directory name must equal `slug`, the slug must
be lowercase kebab-case, and aliases must be unique across the catalogue.

## 2. Write `recipe.yaml`

Start from the schema, not from prose:

```bash
cd resources/recipes/schema
npm install
npm run validate
```

The validator checks, in order:

1. `recipe.yaml` parses and matches `recipe.schema.json` (JSON Schema 2020-12).
2. The directory name equals the slug.
3. Slugs, skill names and aliases are unique across the catalogue.
4. `SKILL.md` exists, has Agent Skills frontmatter, and its `name`/`description` agree with the YAML.
5. Commands contain no `&&` chains — one command per entry.
6. `SKILL.md` is between 60 and 150 lines.

Hard requirements for a merge:

- `schema: lanomat.recipe/v1`, a `status` (`draft` is fine while you iterate), at least one
  `platforms` entry.
- A `skill` block whose `description` says when to use it, including the words a user would say.
- `requires[]` with `role`, `label`, `match`, `required`; **the base game is required**, extras are
  optional.
- At least one step with a `detail`; every non-optional step should end in a `verify` check.
- `metadata` with genre, players, `lan_recommended` and tags.
- Launch data when the game has an installed or browser start: `metadata.launch` (a string, or
  `{kind: steam|uri|exe, target, args?}`) or `metadata.launch_targets` (ids such as `flat` and `vr`,
  first entry is the default). `metadata.play_url` uses `{{host}}`, never `localhost`; `live_url`
  names a live deployment. See `resources/recipes/README.md` § Launching.

## 3. Write `SKILL.md`

The skill is for an agent, not for a search engine. Keep it 60–150 lines and make it operational:

- **When to use** — the exact phrases and the near-misses ("not UT99").
- **What you need** — content, tools, disk, OS, headset.
- **Steps** — the same order as the YAML, with the pitfalls inline.
- **Asking about optional content** — avatars are a multi-select; never turn them into a chain of
  yes/no questions.
- **Verification** — what a successful install looks like.
- **Troubleshooting** — the errors users actually hit, with the fix.
- **License** — who owns the game, what the user supplies.

Do not paste the YAML into the skill. The skill explains intent and judgement; the YAML is the
machine-readable plan.

## 4. Contribute the content

Game files never enter git. Put the ISO, patch or avatar on the LAN file host, then let the recipe
match it by name:

| Item | Where | Naming |
|---|---|---|
| Disc image | file host `isos/` | `ut2004-dvd1.iso`, `r4.chd` |
| Patch | file host `patches/` | keep the upstream name (`OldUnreal-UT2004Patch3374-Windows.zip`) |
| Mod | file host `mods/` | keep the release name (`UT2004VR-1.2.zip`) |
| Avatar | file host `avatars/` | `ayla.vrm`, `brutus.vrm` |
| Recipe | git | `resources/recipes/<slug>/` |

Add `size_bytes` when you know it, and `sha256` if the file is stable. A `match` glob should be
specific enough to avoid grabbing the wrong disc (`ut2004*.iso`, not `*.iso`). If the content is
optional, say so in `notes` and keep `required: false`.

## 5. Review checklist

- [ ] `npm run validate` is green.
- [ ] The base install works with only the required entries.
- [ ] Optional content is genuinely optional and does not block verification.
- [ ] `SKILL.md` names the aliases, the optional questions and the failure modes.
- [ ] Commands are one per entry, quoted, and use `{{target}}`/`{{downloads}}`.
- [ ] No game file, BIOS, ROM or key is in the repository.
- [ ] The recipe page renders (the docs build includes it).
- [ ] The pull request says which platforms you actually tested.

<div class="callout-live">
  <strong>Test on a clean machine.</strong> A recipe that only works on the author's PC is a note,
  not a recipe. At minimum, test the base path on a fresh Windows install and, if you claim Linux,
  in a fresh prefix.
</div>
