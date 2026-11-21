# Lighting: DMX light bridge

The optional `Lighting` module lets the orga steer the room's DMX LED lights from the admin page **Licht**
(`/admin/licht`, Admin and Orga only). LANoMAT never talks DMX itself: it calls the REST API of a light
bridge (a small FastAPI service with a USB-DMX adapter, Swagger at `/docs`).

## Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `MODULE_LIGHTING` | on under `MODULES_PROFILE=full` | Module toggle |
| `LIGHTING_ENABLED` | `false` | Master switch. Off: no calls, no automation, Preflight skips |
| `LIGHTING_URL` | empty | Bridge base URL, e.g. `http://<LIGHT_BRIDGE_HOST>:8060` |
| `LIGHTING_TOKEN` | empty | Optional bearer token, sent as `Authorization: Bearer` and never logged |
| `LIGHTING_ALLOW_STROBE` | `false` | Deployment default for strobe (see below) |
| `LIGHTING_MAX_STROBE` | `120` | Upper limit of the strobe speed when strobe is allowed (10..255) |
| `LIGHTING_GAME_MODE_STROBE_FREE` | `false` | Operator attestation that the bridge's Game Mode does not strobe |

The HTTP timeout is 2 seconds, there are no retries, and no exception reaches the UI: an unreachable
bridge is shown as "Lichtserver nicht erreichbar" and every action is best effort.

## Network

The app server calls the bridge, so the bridge must be reachable from the **app server's network** on
its TCP port (8060 by default). The venue network does not route to the office network on purpose; put
the bridge where the app server can reach it, or run it at the venue and point `LIGHTING_URL` there.
Nothing in the browser talks to the bridge.

## The light plan is read from the bridge

The number of lights and their channels are not fixed. `GET /api/health` reports `fixtures`,
`channels_per_fixture` and `start_addresses`; the page shows exactly the reported lights.

- 6 channels per light (R, G, B, CW, Strobe, Dimmer): colour, brightness, white temperature and level,
  and strobe controls.
- Anything else, or an unreadable health answer: colour only.
- Health unreadable: ids fall back to 1..4. A scene entry for a light the bridge does not report is
  skipped without error. The plan is cached for 30 seconds.

## Response format (verified against the real bridge)

- `GET /api/health`: `{"ok":true,"port":"/dev/ttyUSB0","error":null,"fixtures":2,"channels_per_fixture":4,"start_addresses":[1,7]}`.
  Healthy means `ok` is true and `error` is null.
- `GET /api/lights` is a list of `{"id","name","start_address","red","green","blue","cold_white","warm_white","strobe","dimmer","mode"}`
  with values 0 to 255. The client derives the hex colour from `red`/`green`/`blue`, the brightness from
  `dimmer / 255`, keeps `strobe` raw, and turns `cold_white`/`warm_white` into the white level (the larger
  of the two) and the temperature (share of cold white). Missing fields stay unset. The bridge reports
  `dimmer`, `strobe` and the whites even for 4-channel lights, so the UI offers those controls only when
  `channels_per_fixture` is 6 or more.
- `GET /api/game`: `{"active":false,"mode":"start","config":{"game":{...},"police":{...},"start":{...}}}`.
  The page shows whether an effect runs and its mode, and offers the configured modes (the keys of
  `config`) next to "Game Mode starten". The start body carries `mode` only when one is chosen; the
  bridge's write API for the mode field is assumed, check it at the venue.

## Strobe safety (photosensitivity)

Flashing light can trigger seizures. Strobe is **locked by default**:

- One safety decorator (`SafeLightingClient`) sits in front of every bridge call (page, scenes, rules,
  Artisan). While locked it forces `strobe` to 0 in every command and clears strobe before a pattern.
- When allowed, the speed is capped at `LIGHTING_MAX_STROBE`.
- Game Mode only starts when strobe is allowed or `LIGHTING_GAME_MODE_STROBE_FREE=true`. The bridge does
  not report whether the effect strobes, so only the operator can attest that. Otherwise the start is
  blocked with a clear message.
- Orga can switch strobe on or off at runtime on the page (with a confirmation and a warning). That
  override beats `LIGHTING_ALLOW_STROBE`. Announce strobe in the room before using it.
- The scenes that ship with the module contain no strobe.

## Scenes and rules

Scenes (`lighting_scenes`) hold a state for all lights or per light id, an optional two-colour pattern
(lights 1 and 3 show A, 2 and 4 show B) and a blackout flag. Defaults: Party, Ruhig, Rot-Alarm,
Turnierfinale, Aus. They are managed in the Filament resource "Lichtszenen" and played with the button
"Szene abspielen". Scenes without real hardware only produce calls to the `LightingClient` contract.

Rules (`lighting_rules`, resource "Lichtregeln") map an event to a scene. Every rule is **off by default**
and has a cooldown (default 30 s). Events: tournament started, match ready, match live, match completed,
tournament completed, event live, event finished. Optional scoping by event id or tournament id. A rule
queues a job that plays the scene (no retries, failures swallowed); it only runs when the module is on and
`LIGHTING_ENABLED` is true. Manual actions are limited to 60 per minute and user.

Brightness, white and strobe fields in a scene are sent as they are; a bridge with 4-channel lights
ignores what it cannot show.

## Operations

- Preflight check `lighting`: ok on a healthy bridge, warn when it is unreachable or reports an error
  (lights are never critical), skipped when not configured.
- `php artisan lighting:status` prints health and the state of each light.
- `php artisan lighting:scene "Rot-Alarm"` plays a scene by name or key.

## Manual checklist at the venue

1. Set `LIGHTING_URL`, `LIGHTING_ENABLED=true`, restart app and queue.
2. `lighting:status`: the reported light count matches the room.
3. Colour and brightness on one light, then all lights; blackout.
4. Compare `/api/lights` with the page's state and try the Game Mode modes (the `mode` body field is an assumption).
5. Pattern, then every default scene. Strobe stays locked unless the orga decides otherwise.
6. Try Game Mode only after checking whether it strobes; set `LIGHTING_GAME_MODE_STROBE_FREE` accordingly.
7. While strobe is locked every command carries `"strobe": 0` (and a pattern is preceded by a strobe-only command). If the bridge rejects that field on 4-channel lights, every command fails: check this first.
8. Enable one rule, trigger the event, watch the cooldown.
