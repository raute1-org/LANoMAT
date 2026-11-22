# Discord-Announcement — First Lineup

Stand: **2026-11-21**. Fertiger Text, Webhook-Anleitung und das Ops-Skript
zum Posten. Kein echter Post ist Teil dieses Runbooks.

| Was | Wo |
| --- | --- |
| Announcement-Text (Quelle für `--file`) | [`announcements/2026-11-first-lineup.md`](announcements/2026-11-first-lineup.md) |
| Post-Skript | [`scripts/ops/discord-announce.sh`](../../scripts/ops/discord-announce.sh) |
| Zielkanal | unbekannt — der Webhook gehört zu genau einem Kanal |
| Länge | 873 Zeichen (Discord-Limit: 2000 pro Nachricht) |

## 1. Der Text

Das ist der Inhalt von
[`announcements/2026-11-first-lineup.md`](announcements/2026-11-first-lineup.md).
Die Datei ist die Quelle; Änderungen dort und hier gemeinsam machen.

```markdown
🏁 **First Lineup ist da**

**https://lan.raute1.org** – ab jetzt ist alles an einem Ort:
• **/app**: LANoMAT-App herunterladen (der Launcher für Windows und Linux).
• **/games**: Spiele, Rezepte und Installation.
• **/servers**: Serverbrowser mit allen Dauerservern.

**UT2004 läuft:** `10.23.0.40:7777` (UT2004 VRM, Raute1 LAN).
Beitreten im Serverbrowser oder direkt per `ut2004://10.23.0.40:7777` – den Link-Handler kann der Launcher einrichten.

**NFSU2 im Browser:** https://nfsu2.xyz
Das ist der Community-Port „Underground 2 Revived“ – nicht unser Server. Chrome oder Firefox mit WebGPU, kein Account nötig: Nickname eintragen, auf den einen Server joinen, fahren, am Ende wieder leaven. Bei vollem Server gibt es eine Warteschlange.

**Unser eigener NFSU2-Server (NFS-Online) folgt.** Adresse und Client stehen dann hier – nfsu2.xyz bleibt der Community-Port.

Fragen? Ab in den Chat.
```

Inhaltliche Leitplanken:

- `https://lan.raute1.org` ist die LANoMAT-Website; `/app`, `/games` und
  `/servers` sind ihre Seiten (`/launcher` leitet auf `/app` um).
- `10.23.0.40:7777` ist **unser** UT2004-Server (UT2004 VRM, Raute1 LAN).
- `nfsu2.xyz` ist der **Community-Port** „Underground 2 Revived“ (inoffizielles
  Fan-Projekt, nicht von EA) — **nicht unser Server** und nicht der
  NFS-Online-Server aus [`nfsu2-server.md`](nfsu2-server.md).
- Der eigene NFSU2-Server (NFS-Online) ist noch nicht live. Deshalb steht dort
  „folgt“ und keine Adresse. Keine Adresse aus dem Laptop-Test
  (`10.23.3.220`) veröffentlichen.

## 2. Webhook anlegen

Ein Discord-Webhook ist an **einen** Kanal gebunden. Wer den Webhook hat, kann
in diesem Kanal posten — die URL ist ein Secret.

1. Discord öffnen, Zielkanal wählen (Rechtsklick → **Kanal bearbeiten**).
2. **Integrationen → Webhooks → Neuer Webhook** (oder **Webhook erstellen**).
3. Name z. B. `LANoMAT`, Kanal prüfen, **Webhook-URL kopieren**.
4. Die URL nur lokal ablegen, nicht ins Repo:

   ```bash
   install -d -m 700 ~/.config/lanomat
   install -m 600 /dev/null ~/.config/lanomat/discord-webhook
   printf '%s' '<webhook-url>' > ~/.config/lanomat/discord-webhook
   ```

   Alternativ pro Aufruf aus der Umgebung, ohne History-Eintrag:

   ```bash
   read -rsp 'Discord-Webhook: ' DISCORD_WEBHOOK_URL; echo
   export DISCORD_WEBHOOK_URL
   ```

Berechtigung: Wer den Kanal verwaltet, darf Webhooks anlegen
(**Webhooks verwalten**). Der Webhook braucht keine Bot-Rolle; er postet in
dem Kanal, in dem er angelegt ist.

## 3. Kanal

Der Kanal ist nicht im Repo hinterlegt und wird nicht geraten. Der Webhook
bestimmt ihn: Er wird im Zielkanal angelegt, also dort landet der Post.

Wenn der Post über einen **Bot** statt über einen Webhook laufen soll, braucht
es stattdessen:

- `DISCORD_ANNOUNCE_CHANNEL_ID` — ID des Zielkanals (17–20 Ziffern),
- `DISCORD_BOT_TOKEN` — Bot-Token mit **Nachrichten senden** im Kanal und
  Zugriff auf die Guild.

Das ist hier **nicht implementiert**: Den Bot-Post übernimmt der Discord-Agent;
der Gateway-Sidecar liefert nur die Events. Das Skript postet ausschließlich
per Webhook. Eine echte Channel-ID gehört wegen
[`scripts/ops/check-public-safe.sh`](../../scripts/ops/check-public-safe.sh)
nicht in eine getrackte Datei (Snowflake-Erkennung); nur in die Umgebung.

## 4. Trockenlauf

`--dry-run` braucht keinen Webhook und sendet nichts. Es druckt die exakte
JSON-Payload, die `curl` sonst an Discord schickt:

```bash
scripts/ops/discord-announce.sh \
  --file docs/ops/announcements/2026-11-first-lineup.md \
  --dry-run
```

Erwartete Ausgabe (gekürzt; `content` ist der volle Text):

```json
{
  "content": "🏁 **First Lineup ist da**\n\n**https://lan.raute1.org** – ab jetzt ist alles an einem Ort:\n…",
  "allowed_mentions": {
    "parse": []
  }
}
dry run: 1 message(s) prepared, nothing sent
```

Der Exit-Code ist `0`; die letzte Zeile geht nach stderr. Mehrere Teile
erscheinen als mehrere JSON-Objekte hintereinander.

## 5. Posten

```bash
# Webhook aus der Umgebung (bevorzugt)
DISCORD_WEBHOOK_URL='…' scripts/ops/discord-announce.sh \
  --file docs/ops/announcements/2026-11-first-lineup.md

# Webhook aus einer 0600-Datei
scripts/ops/discord-announce.sh \
  --file docs/ops/announcements/2026-11-first-lineup.md \
  --webhook-file ~/.config/lanomat/discord-webhook

# Optionaler Absendername und Avatar (überschreiben den Webhook-Namen)
scripts/ops/discord-announce.sh \
  --file docs/ops/announcements/2026-11-first-lineup.md \
  --username LANoMAT \
  --avatar https://lan.raute1.org/apple-touch-icon.png
```

Optionen:

| Option | Wirkung |
| --- | --- |
| `--file <path>` | Markdown-Datei; `--file -` liest stdin |
| `--webhook <url>` | Webhook-URL als Argument (sichtbar in History und `ps`) |
| `--webhook-file <path>` | Webhook-URL aus einer Datei (z. B. Modus `0600`) |
| `--dry-run` | Payload drucken, nichts senden; kein Webhook nötig |
| `--username <name>` | Anzeigename des Posts (max. 80 Zeichen) |
| `--avatar <url>` | Avatar-URL (`http`/`https`) |
| `--max-chars <n>` | Limit pro Nachricht, 200–2000 (Default 2000) |
| `--no-chunk` | Zu langer Text ist ein Fehler statt mehrerer Nachrichten |

Reihenfolge der Webhook-Quellen: `--webhook`, `--webhook-file`,
`DISCORD_WEBHOOK_URL`, `DISCORD_WEBHOOK_FILE`.

Das Skript sendet `allowed_mentions: {"parse": []}`. Auch wenn im Text
`@everyone`, eine Rolle oder ein User steht, pingt der Post niemanden.

### Länge und Chunking

Discord erlaubt **2000 Zeichen** pro Nachricht. Der First-Lineup-Text hat 873
und passt in eine Nachricht. Längerer Text wird an Leerzeilen geteilt, dann an
Wortgrenzen; die Teile bekommen Präfixe `[1/2] …`, `[2/2] …`. Mit
`--no-chunk` bricht das Skript stattdessen mit Exit `3` ab.

### Exit-Codes

| Code | Bedeutung |
| --- | --- |
| `0` | gesendet (oder Dry-Run gedruckt) |
| `1` | Request fehlgeschlagen (Netz, HTTP ≠ 2xx, 429 auch nach Retry) |
| `2` | Aufruffehler (unbekannte Option, ungültige URL, fehlendes Werkzeug) |
| `3` | Inhaltsproblem (leer, zu lang mit `--no-chunk`, Datei fehlt/unlesbar) |
| `4` | Webhook fehlt und kein `--dry-run` |

## 6. Sicherheit

| Regel | Konkret |
| --- | --- |
| Webhook ist ein Secret | Wer die URL hat, kann im Kanal posten. Nicht committen, nicht in Tickets/Chats kleben. |
| Nicht in der Prozessliste | `--webhook` steht in Shell-History und `ps`; `DISCORD_WEBHOOK_URL` oder `--webhook-file` (Modus `0600`) bevorzugen. |
| Keine Ausgabe der URL | Das Skript druckt die Webhook-URL nie; auch curl-Fehler werden auf `<webhook>` redigiert. |
| Keine Pings | `allowed_mentions: {"parse": []}` — kein `@everyone`, keine Rollen, keine User-Pings. |
| Nur Discord-Hosts | Akzeptiert werden `https://discord.com/api/webhooks/…`, `discordapp.com`, `canary.` und `ptb.`; `http://` wird abgelehnt. |
| Kein Doppelpost im Test | `--dry-run` sendet nichts. Ohne Webhook endet das Skript mit Exit `4`, nie mit einem stillen Post. |
| Nach dem Post rotieren | Wenn die URL länger lag: Webhook im Kanal löschen und neu anlegen. |
| Keine echten Posts aus CI | Das Skript läuft nur manuell; es gibt keinen Scheduler und keinen Test, der es aufruft. |
| Öffentliches Repo | `10.23.0.40` ist bewusst im Text und in diesem Runbook; die Allowlist-Einträge stehen in [`public-safe-allowlist.txt`](../../scripts/ops/public-safe-allowlist.txt). |

## 7. Checkliste vor dem echten Post

1. Zielkanal und Webhook stehen fest; die URL liegt in der Umgebung oder in
   einer `0600`-Datei.
2. `--dry-run` gegen die finale Textdatei: Payload und Länge prüfen.
3. UT2004 erreichbar (`10.23.0.40:7777`) und `https://lan.raute1.org/app`
   lädt (`/launcher` leitet dorthin um).
4. `nfsu2.xyz` öffnet im Browser mit WebGPU; der Text nennt es weiterhin
   „Community-Port“, nicht „unser Server“.
5. Posten, im Kanal einmal gegenlesen (Links, keine Vorschau-Überraschung).
6. Danach `unset DISCORD_WEBHOOK_URL` bzw. die Webhook-Datei löschen, wenn sie
   nicht weiter gebraucht wird.

Live-Check **2026-11-21**: Die UT2004-Query auf `10.23.0.40:7778` antwortet
(Name `Raute1 LAN`, 0/16 Spieler), `nfsu2.xyz` ist erreichbar.
`https://lan.raute1.org/launcher` liefert derzeit **404** — die Route ist in
diesem Checkout vorhanden, der Live-Build ist älter. Vor dem Post deployen oder
den Launcher-Satz aus dem Text nehmen; sonst führt der Link ins Leere.
