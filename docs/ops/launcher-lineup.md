# Launcher First Lineup — Betrieb

Stand: **2026-11-21**. Gilt für Launcher, Web-Bibliothek, Home-Chat und den
MCP-Katalog. Keine Live-Änderung, kein Secret und kein Commit in diesem
Runbook.

## 1. Was die Lineup ist

Die erste Lineup sind die drei getesteten Spiele:

| Slug | Weg | Install |
| --- | --- | --- |
| `unreal-tournament-2004` | Launcher (Single-Click), Serverbrowser | ja, `System/`-Verifier + OldUnreal-Patch |
| `need-for-speed-underground-2` | Launcher (Single-Click), NFS-Online-Server | ja, Basis-ZIP + NFS-Online-ASI; Wine auf Linux |
| `need-for-speed-underground-2-browser` | Browser `https://nfsu2.xyz/` | nein, nur „Spielen“ |

Alles andere (Apocalypse, Azeroth, Zelda, CS, Ridge Racer, …) bleibt in der
Datenbank und im Filament-Admin, ist aber aus den Spieler-Oberflächen
ausgeblendet:

- Launcher-Library (`list_games` über `/api/v1/recipes`)
- Web `/games` und `/games/{slug}`
- Home-Chat-Karten (`HomeChatData`)
- MCP `recipes_list`

Die Rezepte bleiben synchronisierbar; die Lineup ist nur ein Read-Filter.

## 2. Konfiguration

```dotenv
# config/launcher.php -> lineup
LAUNCHER_LINEUP=unreal-tournament-2004,need-for-speed-underground-2,need-for-speed-underground-2-browser
```

- Komma-getrennte Slugs, Leerzeichen werden getrimmt, Kleinschreibung ist
  erlaubt, Duplikate und ungültige Einträge werden ignoriert.
- **Leer** (`LAUNCHER_LINEUP=`) heißt „keine Kuratierung“: alle sichtbaren
  Rezepte erscheinen wieder. Das ist der Backcompat-Pfad für bestehende
  Installationen.
- Der Default in `config/launcher.php` und in `LauncherLineup::DEFAULT` sind
  die drei Slugs oben. Ein Test-Environment setzt die Lineup nicht; einzelne
  Suites, die den ganzen historischen Katalog prüfen, setzen
  `launcher.lineup` in ihrem `beforeEach` auf `''`.

### Geteilte Spiele: das `in_lineup`-Flag

`LauncherLineup::slugs()` ist die konfigurierte Liste **plus** jedes Rezept mit
`game_recipes.in_lineup = true`. Das Flag setzt die Orga:

- automatisch beim **Veröffentlichen eines geteilten Spiels** (Aktion
  „Veröffentlichen“, „Dateien freigeben und veröffentlichen“ oder ein
  Formular-Speichern, das den Status auf `published` dreht — `PublishRecipe`);
- von Hand im Rezept-Formular mit **„In der Spieleliste zeigen“**
  (`in_lineup`, nur für Orga/Admin bedienbar).

Damit erscheint ein geteiltes Spiel auf `/games`, im Launcher und neben seinen
Servern, ohne dass jemand `LAUNCHER_LINEUP` ändert oder den Config-Cache
leert. Ein Rezept außerhalb der Liste bleibt in der Datenbank und im
Filament-Admin sichtbar; nur die Spieler-Oberflächen filtern.

**Unrestricted-Fall:** Ist `LAUNCHER_LINEUP` leer, ist die Lineup
„uneingeschränkt“ — jedes veröffentlichte Rezept erscheint, unabhängig vom
Flag. Das Flag fügt also nur zu einer kuratierten Liste hinzu; es schaltet
nie etwas ab und es ersetzt keine Kuratierung.

Nach einer Änderung:


```bash
php artisan config:clear
# oder im Container:
docker compose exec app php artisan config:clear
```

Ein Config-Cache muss neu gebaut werden, sonst bleibt die alte Lineup aktiv.

## 3. Browser-Rezept

`resources/recipes/need-for-speed-underground-2-browser/`

- `status: published`, `platforms: [windows, linux, macos]`
- `play_url: https://nfsu2.xyz/`
- `requires: []` — kein `base`, kein `patch`, keine installierbaren Dateien
- `steps` beschreiben nur Browser-Check, Join/Leave und optionale Saves
- `metadata.server` liefert die eine Zeile und den einen Server:

```yaml
server:
    name: 'NFSU2 (Browser)'
    address: nfsu2.xyz
    port: 443
    connect_url: 'https://nfsu2.xyz/'
    players: 'Ein Server · Nickname · Join/Leave'
```

Der Launcher erhält im Katalog `installable: false` und `play_url`; seine
Karte sagt **Spielen** und ruft `open_url` auf. Es laufen keine
Install-/Verify-Aufrufe. Web `/games` zeigt dieselbe Aktion und die
Serverzeile; `/games/{slug}` listet den Browser-Server mit HTTPS-Join.

Der native NFSU2-Pfad (`need-for-speed-underground-2`) liegt in einem eigenen
Rezept und wird von der Browser-Recipe nicht überschrieben.

## 4. Server im Serverbrowser

UT2004 bleibt der bestehende Dauerserver `10.23.0.40:7777`; die Lineup ändert
ihn nicht.

Für NFSU2 gibt es keinen von LANoMAT gesteuerten Prozess. Der eine manuelle
Server wird einmalig registriert:

```bash
php artisan db:seed --class=LauncherLineupSeeder
```

Der Seeder ist idempotent und legt an:

| Feld | Wert |
| --- | --- |
| Titel | `NFSU2 (Browser)` |
| Adresse | `nfsu2.xyz` |
| Port | `443` |
| Connect | `https://nfsu2.xyz/` |
| Typ | permanent, extern, manuell, `Ready` |

Der `join`-Button öffnet HTTPS. Der Seeder fasst keinen anderen Server an und
wird nicht automatisch von `DatabaseSeeder` ausgeführt.

Ohne Seeder bleibt die Web-Spielseite korrekt: sie leitet den einen Server aus
`metadata.server` + `play_url` ab. Der globale `/servers`-Browser zeigt ihn
erst nach dem Seeder (dort gibt es keine Rezept-Ableitung).

## 5. Tests

```bash
# PHP: Lineup, Web, MCP, Serde-nahe Serverprojektion
php artisan test tests/Feature/GameRecipes/LauncherLineupTest.php

# betroffene Bestands-Suites (Lineup dort bewusst leer)
php artisan test \
  tests/Feature/GameRecipes/GamesPageTest.php \
  tests/Feature/Events/HomeChatTest.php \
  tests/Feature/Mcp/RecipeIndexApiTest.php \
  tests/Feature/Mcp/McpToolsTest.php

# Rust: RecipeSummary/play_url und launcher_supports
cargo test -p launcher-core
cargo test -p lanomat-launcher-app --lib a_browser_recipe_is_supported

# Rezept-Schema (Warnung „kein base/iso“ ist für eine Browser-Recipe erwartet)
cd resources/recipes/schema && node validate.mjs
```

## 6. Grenzen

- Die Lineup ist **kein** Sicherheitsfilter: die Rezepte bleiben über direkte
  Admin-/Sync-Pfade erreichbar. Sie steuert nur die Spieler-Oberflächen.
- Ein Lineup-Slug, der nicht publiziert ist, erscheint im Web nicht; der
  Launcher sieht ihn nur, wenn der Nutzer ihn sehen darf (`visibleTo`).
- Ein veröffentlichtes geteiltes Spiel steht über das Flag sofort auf
  `/games`; der Launcher kann es aber nur installieren, wenn
  `LauncherSupport` es als portabel (oder per `metadata.launcher_supported`)
  einstuft. Sonst führt „Installieren“ auf die Spielseite mit den manuellen
  Schritten.
- Der Browser-Port braucht Internet und WebGPU. Er ist ein Fallback, kein
  Ersatz für den nativen NFSU2-Server.
