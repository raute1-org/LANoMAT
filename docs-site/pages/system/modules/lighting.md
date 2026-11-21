---
title: Lighting
description: DMX room lights from the admin panel, through the venue's light bridge.
sidebar_icon: lightbulb
---

`Lighting` lets the orga steer the room's LED lights from the admin page **Licht**: colour, brightness,
white, an alternating two-colour pattern, a big blackout button and the bridge's Game Mode. Scenes
(Party, Ruhig, Rot-Alarm, Turnierfinale, Aus) play with one click, and optional rules play a scene on a
tournament or event moment.

## Who uses it

- **Orga** controls the lights and decides about rules and strobe.
- **Teilnehmer** and **Helfer** never see the page.

## Key screens

- Filament **Licht** with the live state of each light the bridge reports.
- Filament **Lichtszenen** and **Lichtregeln**.
- Preflight, which warns when the light bridge is unreachable.

## Guards and rules

- Strobe is locked by default (photosensitivity) in every path: page, scenes, rules and Game Mode.
- Rules are off until switched on and have a cooldown.
- The bridge is optional: when it is off, the page says so and nothing fails.

Operator detail: `docs/infra/lighting.md` in the repository.
