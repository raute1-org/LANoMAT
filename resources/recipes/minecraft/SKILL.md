---
name: minecraft
description: 'Install Minecraft: Java Edition 26.3 on a LAN PC through portable Prism Launcher 11.1.1, import or create the LAN Fabric instance (Sodium, Lithium, Fabric API), sign in with Microsoft, add the LAN server and launch with the right Java 25 runtime. Use when the user says install Minecraft, Minecraft Java, MC, Minecraft Fabric, or asks to join the LAN Minecraft server.'
---

# Minecraft: Java Edition on the LAN

Minecraft Java is the LAN's block sandbox. This skill installs the game
through **Prism Launcher 11.1.1** (portable, Windows/Linux/macOS) and joins the
LAN server. It targets **Minecraft 26.3 "Wilderness Bound"**, the current
release from 15 September 2026, with **Fabric Loader 0.19.5** and the client
performance mods.

Status: **draft recipe, researched online on 2026-11-21**. It needs
verification on the LAN hardware: the portable extracts, Microsoft sign-in,
Java 25 selection, the imported instance and a real client/server join still
have to be checked on the venue PCs and the venue network.

## When to use

Use it when the user says any of:

- "install Minecraft", "install Minecraft Java", "install MC"
- "set up Minecraft for the LAN"
- "join the LAN Minecraft server"
- "install Fabric / Sodium / Lithium for Minecraft"

Do not use it for Bedrock Edition, the Microsoft Store build, or a
browser-only clone. Those are different clients and cannot join a Java server.

## What you need

- **Prism Launcher 11.1.1 portable** for the user's OS, from the official
  release: https://github.com/PrismLauncher/PrismLauncher/releases/tag/11.1.1
  - Windows x86-64: `PrismLauncher-Windows-MinGW-w64-Portable-11.1.1.zip`
  - Linux x86-64: `PrismLauncher-Linux-Qt6-Portable-11.1.1.tar.gz`
  - macOS 12+: `PrismLauncher-macOS-11.1.1.zip`
- **Minecraft 26.3 + Fabric 0.19.5**. Prism downloads the game files from
  Mojang after the Microsoft sign-in; the LAN does not redistribute them.
- **Java SE 25**, the minimum for Minecraft 26.x. Prism downloads a matching
  runtime automatically; a manually installed Java 25 also works.
- Optional but preferred: **`minecraft-lan*.zip`**, a Prism instance export
  from the orga with 26.3, Fabric 0.19.5, Sodium, Lithium, Fabric API and the
  LAN server already in the multiplayer list.
- A **Microsoft account** for online mode. Offline accounts only work against
  a server with `online-mode=false`.
- The **LAN server address** from the orga or the LANoMAT server page. The
  default Java port is 25565.

## Steps

1. **Unpack Prism into `{{target}}`.**
   - Windows: unzip the portable ZIP flat, so `prismlauncher.exe` and
     `portable.txt` sit directly in `{{target}}`.
   - Linux: `tar -xzf ... -C "{{target}}" --strip-components=1` so
     `bin/prismlauncher` and `portable.txt` sit at the root.
   - macOS: unzip `Prism Launcher.app` into `{{target}}` and start it with
     `-d "{{target}}/data"` so accounts and instances stay with the install.
   The portable marker on Windows/Linux keeps data in the folder; the `-d`
   flag does the same on macOS and is harmless on the other systems.
2. **Start Prism once and add the Microsoft account.** Accounts -> Add
   Microsoft account, finish the browser flow, return to Prism. If the venue
   has no stable uplink, do this before the LAN night.
3. **Select Java 25.** Edit -> Settings -> Java. Pick the runtime Prism
   downloaded, or point at the system Java 25. Set memory to about 4 GB on an
   8 GB PC (6-8 GB on 16 GB). Do not use Java 21: 26.x refuses it.
4. **Import or create the instance.**
   - Preferred: Add Instance -> Import, choose `minecraft-lan*.zip` (or a
     `.mrpack`), keep the name `lanomat-26.3-fabric`.
   - Manual: Add Instance -> Minecraft 26.3 -> Fabric 0.19.5, then Mods ->
     install Sodium, Lithium and Fabric API for 26.3 from Modrinth.
   Keep the instance ID `lanomat-26.3-fabric` if the launch target should
   work unchanged; otherwise rename the target's `-l` argument.
5. **Start the instance once.** Let Prism download the 26.3 game files and
   assets. Check that the title screen shows 26.3 and that Fabric loaded
   (Mods button lists Sodium/Lithium). This is the offline-ready state.
6. **Join the LAN server.** Multiplayer -> Add Server, name `LANoMAT`, paste
   `host:port`, join. With the imported instance the entry is already there.
   Everyone must use the same Minecraft version and the same mods as the
   server. For a player-hosted world, use Open to LAN and share the printed
   port instead; the host has to keep the world open.

## Starting the game

- **Windows:** `Start-Process -FilePath (Join-Path "{{target}}" 'prismlauncher.exe') -ArgumentList '-d', (Join-Path "{{target}}" 'data'), '-l', 'lanomat-26.3-fabric'`
- **Linux:** `"{{target}}/bin/prismlauncher" -d "{{target}}/data" -l lanomat-26.3-fabric`
- **macOS:** `open -a "{{target}}/Prism Launcher.app" --args -d "{{target}}/data" -l lanomat-26.3-fabric`

The Prism CLI supports `-l <instance>` and `-s <server>` together, so a
later one-click join can pass the LAN server address without changing the
instance. The current recipe launches the instance; the server is in the
multiplayer list.

## Online vs offline mode

- **Online (default):** the server has `online-mode=true` and every player
  signs in with a Microsoft account. Use this at the LAN unless there is a
  reason not to.
- **Offline:** the server has `online-mode=false` in `server.properties`.
  Prism can use an offline account with a chosen name, but each join gets a
  fresh UUID, so inventories, whitelists and permissions do not survive.
  Use it only for a throwaway event world.
- The LAN server runs Java 25 for 26.x. Do not start a 26.3 server with
  Java 21; it exits with an unsupported class version.

## Verification

- `{{target}}/prismlauncher.exe` (Windows) or
  `{{target}}/bin/prismlauncher` (Linux) exists; `portable.txt` is present.
- macOS has `{{target}}/Prism Launcher.app`.
- Prism shows a Microsoft account, Java 25 and the `lanomat-26.3-fabric`
  instance.
- The instance has Minecraft 26.3 and Fabric 0.19.5; Mods lists Sodium and
  Lithium when the LAN pack is used.
- The multiplayer list contains the LAN server and a join reaches the world.
- Still to verify on the LAN hardware: portable start on a fresh Windows PC,
  Java 25 download with the venue firewall, Microsoft sign-in without a
  stable uplink, and the same mod set on client and server.

## Sources

- Minecraft Java Edition 26.3 release notes:
  https://feedback.minecraft.net/hc/en-us/articles/48913133328013
- Java requirement (Java SE 25 for 26.x):
  https://minecraft.wiki/w/Java_Edition_26.3
- Prism Launcher 11.1.1 downloads and portable data locations:
  https://prismlauncher.org/download/ ,
  https://prismlauncher.org/wiki/getting-started/data-location/
- Prism command line (`-d`, `-l`, `-s`, `-I`):
  https://prismlauncher.org/wiki/getting-started/command-line-interface/
- Fabric for Minecraft 26.3 (Loader 0.19.5):
  https://fabricmc.net/2026/09/15/263.html
- Fabric API for 26.3:
  https://modrinth.com/mod/fabric-api
- Sodium and Lithium for 26.3:
  https://modrinth.com/mod/sodium , https://modrinth.com/mod/lithium
- Offline mode caveats:
  https://teramont.net/blog/minecraft-server-offline-mode-online-mode-false
