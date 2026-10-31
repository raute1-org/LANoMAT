# Mumble: what the changelogs mean for us

Read from the release announcements of every Mumble release since the version we pinned
(`mumblevoip/mumble-server:v1.4.230`, January 2022) up to 1.5.939 and the 1.6 release candidates
(announcements linked at the end). Facts below are quoted from those changelogs; what is **our reading** is marked
as such. All names and addresses are placeholders.

## Where we stand

| Version    | Date       |                                                        |
| ---------- | ---------- | ------------------------------------------------------ |
| 1.4.230    | 2022-01    | what `compose.yml` pinned until now                    |
| 1.5.634    | 2024-05    | first stable 1.5                                       |
| 1.5.939    | 2026-10-11 | latest stable 1.5 (sixth stable release of the series) |
| 1.6.0 RC 2 | 2026-10-25 | release candidate, not stable yet                      |

## Reasons to move to 1.5.939

A Mumble server on a LAN is reachable by everyone on it, so crash and denial-of-service bugs count even without
an internet exposure. After 1.4.230 the server side got, among others:

- 1.5.857: a missing permission check on `msgQueryUsers` and extended user stats now needing the Ban ACL; packets
  of exactly the maximum size were rejected; the server refuses to start on empty certificate files.
- 1.5.901: an Opus-related out-of-bounds memory access (CVE-2025-71264), a crash on invalid varint encodings, writes
  to an invalid socket, the first audio packet of a new whisper being dropped.
- 1.5.915: a server crash caused by malformed packets.
- 1.5.939: no data flow to or from unauthenticated connections; various server-side fixes for crashes and denial of
  service; a password generator that did not use the full alphabet.

## What breaks, what does not

- **Ice module name.** 1.5 renamed the Ice module and slice file from `Murmur` to `MumbleServer`; the operations the
  sidecar uses (`getChannels`, `getUsers`, `addChannel`, `removeChannel`, `getChannelState`, `setChannelState`,
  `getServer`) are unchanged (compared in the two slice files). `docker/mumble-admin` now bundles both slices and
  uses the module the server answers for, so it works with 1.4 and 1.5 (round trip tested against real servers of
  both versions).
- **gRPC was removed** in 1.5. We never used it (the sidecar talks Ice).
- **The image runs the server as uid/gid 10000** (the 1.4 image used 1000). A private key shared through a
  group must therefore use group 10000 (`CERTS_GID`, see [local-service-tls.md](local-service-tls.md)); with the
  old group the server exits at start ("Failed to read ... privatekey.key"). Found while upgrading the test VM.
- **Environment variables** of the official image (`MUMBLE_CONFIG_*`, `MUMBLE_SUPERUSER_PASSWORD`) work unchanged
  in 1.5.939, including the empty values our `compose.yml` passes when no password or certificate is set.
- **Opus is enforced by default** and the old codecs are gone: no change for us, the web voice and the
  Mumble match rooms use Opus anyway.
- **UDP voice packets moved to Protobuf** in 1.5. Whether every old 1.4 client still connects to a 1.5 server was
  not verified here; ask the participants for current clients (1.5.x) anyway.

## Upgrading the test server

1. Back up the data volume: `docker run --rm -v <PROJECT>_mumble_data:/d -v "$PWD":/b alpine tar czf /b/mumble-data.tgz -C /d .`
2. Set the new tag in `compose.yml` (done here), `docker compose --profile prod up -d --force-recreate mumble mumble-admin`.
3. The server migrates its SQLite database on first start; a downgrade needs the backup.
4. `php artisan lanomat:preflight` shows the Mumble probe; create a test channel from the app.
5. 1.6 will switch the database backend and ships JSON import/export for it: do not jump to the release candidate
   on a server whose data matters, wait for the stable release and read its notes.

## Things in the changelogs we could use (our reading, not built)

- **Channel listeners, persistent for registered users since 1.5**: an orga member can _listen_ to a match room
  without joining it, which fits "admins see every room".
- **ACLs per channel** (Enter, Traverse, Write, Listen): private team rooms like the Discord ones are possible with
  the Ice ACL calls. 1.5.857 fixed how `Traverse` and `Write` are parsed and inherited, which matters if we start
  using them. It needs users registered with a certificate, so it is real work.
- **Bot flag** (1.5.634): connections can be flagged as bots; relevant only if we ever add a bot client such as a
  recorder or a bridge.
- **`rememberchannelduration`** was fixed twice (1.4.274, 1.5.634); relevant if channels should remember their last
  users.
- **Temporary channels**: permission checks and moving them were fixed in 1.5.634 and 1.5.735. Channels created
  through Ice are permanent (the `temporary` flag has no effect there), so LANoMAT deletes its rooms itself.

## Latency

The changelogs contain no server-side latency setting and no measured latency numbers. The relevant knobs are
client-side (audio per packet, 10 ms or 20 ms, and the jitter buffer) and the Opus frame size, which Mumble sends as
one frame per packet. For a number we trust we measure on our own network: see the web voice runbook
([livekit-web-voice.md](livekit-web-voice.md)).

## Sources

Release announcements: [1.4.230](https://www.mumble.info/blog/mumble-1.4.230/),
[1.4.274](https://www.mumble.info/blog/mumble-1.4.274/), [1.4.287](https://www.mumble.info/blog/mumble-1.4.287/),
[1.5.517 RC](https://www.mumble.info/blog/mumble-1.5.517-rc/),
[1.5.634](https://www.mumble.info/blog/mumble-1.5.634/), [1.5.735](https://www.mumble.info/blog/mumble-1.5.735/),
[1.5.857](https://www.mumble.info/blog/mumble-1.5.857/), [1.5.901](https://www.mumble.info/blog/mumble-1.5.901/),
[1.5.915](https://www.mumble.info/blog/mumble-1.5.915/), [1.5.939](https://www.mumble.info/blog/mumble-1.5.939/),
[1.6 RC](https://www.mumble.info/blog/mumble-1.6.870-rc/),
[1.6 RC 2](https://www.mumble.info/blog/mumble-1.6.1001-rc-2/); the releases on
[GitHub](https://github.com/mumble-voip/mumble/releases).
