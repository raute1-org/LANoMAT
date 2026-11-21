---
title: Room audio
description: Denon AV receiver control from the admin panel, through a telnet sidecar.
sidebar_icon: volume-2
---

`RoomAudio` lets the orga steer the venue's Denon AV receiver from the admin page **Raumsteuerung**:
power, volume with a ceiling, mute, input and sound mode. Music Assistant plays the music to the
receiver over HEOS; LANoMAT only steers the receiver and never sends audio.

## Who uses it

- **Orga** controls the room sound; a volume ceiling protects the PA and the people.
- **Teilnehmer** never touch the receiver; they vote for tracks in the Jukebox.

## Key screens

- Filament **Raumsteuerung** — power, volume, mute, source, mode, receiver state.
- Preflight — receiver reachability (when configured).

## Guards and rules

- The receiver's telnet port has no authentication. Only the `denon-admin` sidecar talks to it, and
  the sidecar must not be reachable from outside the venue network.
- The app talks HTTP to the sidecar through the `ReceiverClient` contract with a shared bearer
  token. Without a token the page says unreachable and actions fail calmly.
- The volume ceiling is stored in `RoomAudioSetting`; a volume above it is rejected, not clamped
  silently.
- Every action is authorized by `RoomAudioPolicy` (orga).
- A sidecar outage degrades room audio only; the Jukebox queue keeps working locally.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>One telnet session.</strong> The sidecar holds the only connection to the receiver. Two
  controllers would fight over the port and the state.
</div>
