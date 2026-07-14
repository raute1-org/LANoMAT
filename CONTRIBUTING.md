# Contributing zu LANoMAT

Danke für dein Interesse an LANoMAT – dem modularen LAN-Party-Management-Tool
(Events, Anmeldung, Sitzplan, Turniere, Discord, Mumble, Gameserver …).

Es gibt zwei Wege, beizutragen:

| Wer | Was |
|-----|-----|
| **Alle** | Feature Requests stellen (GitHub-Issue) |
| **Mitglieder der Organisation `raute1-org`** | Code beitragen (Pull Requests) |

---

## Feature Requests – offen für alle

**Jede und jeder kann ein Feature vorschlagen** – dafür ist keine Mitgliedschaft in
der Organisation nötig.

1. Öffne den **Issues**-Tab → **New issue** → **Feature Request**.
2. Fülle das Formular aus (Problem/Motivation, Vorschlag, ggf. Alternativen).
3. Sende es ab – das Team sichtet den Vorschlag und ordnet ihn ggf. der Roadmap zu.

Bitte vorher kurz die **Roadmap** prüfen, ob dein Wunsch schon erfasst ist:

- **Milestones** `M0`–`M7`: <https://github.com/raute1-org/LANoMAT/milestones>
- **Projects-Board „LANoMAT Roadmap"**: <https://github.com/orgs/raute1-org/projects/2>
- Detaillierte Planung: [`docs/superpowers/plans/`](docs/superpowers/plans/)

---

## Code beitragen – Pull Requests durch Organisationsmitglieder

**Pull Requests werden von Mitgliedern der Organisation `raute1-org` erstellt.**
Bist du kein Mitglied, aber möchtest etwas beitragen? Stell dein Anliegen als
Feature Request (siehe oben) – die Umsetzung übernimmt das Team.

### Setup

```bash
docker compose up -d        # Postgres (Host-Port 5434) + Redis (Host-Port 6380)
composer install
npm install
composer run dev            # App + Queue + Vite (via php artisan dev)
```

Details zum Stack und den Kommandos: [`CLAUDE.md`](CLAUDE.md) und
[`docs/architecture.md`](docs/architecture.md).

### Workflow

1. **Branch von `main`** anlegen (`feat/<kurzbeschreibung>`, `fix/<…>`).
2. **TDD**: erst der fehlschlagende Test, dann die Implementierung.
3. Die **Konventionen** einhalten (siehe unten).
4. **Alle Gates lokal grün** laufen lassen (siehe unten).
5. **Pull Request gegen `main`** öffnen – Titel als Conventional Commit,
   Beschreibung mit Kontext/Motivation. Die **CI muss grün sein**; nach Review
   durch das Team wird gemergt (Squash).

### Konventionen

- **Conventional Commits**: `feat(scope): …`, `fix(scope): …`, `docs: …` usw.
- **Sprache**: Code, Kommentare, Commits und Doku auf **Englisch**; UI-Texte auf
  **Deutsch** über `lang/de/*.php` (keine hartkodierten Strings in Komponenten).
- **Modularer Monolith**: `app/Modules/<Name>/{Models,Actions,Policies,Filament,Jobs,Events,Contracts}`;
  Tests gespiegelt in `tests/{Feature,Unit}/<Name>/`. Module kommunizieren über
  Events und explizite Interfaces – niemals in fremde Modul-Tabellen greifen.
- **Actions-Pattern**: eine Klasse pro Use-Case; Controller/Filament bleiben dünn.
- **Autorisierung immer über Policies** – nie Client-gelieferten User-IDs vertrauen.
- **PHP**: Pint (Laravel-Preset), Larastan **Level 8**, Enums statt Magic Strings,
  keine `mixed`-Rückgaben in eigenem Code.
- **Vue**: `<script setup lang="ts">`, nur Tailwind + shadcn-vue, keine `<style>`-Blöcke.
- **State of the Art (2026)**: vor dem Einbauen die aktuelle offizielle Doku prüfen
  (context7 / laravel-boost); keine veralteten Patterns.
- **Uploads** in Laravel Storage, nie Base64 in die DB.

### Gates – müssen grün sein (lokal **und** in der CI)

```bash
composer check          # pint --test, phpstan (level 8), pest
npm run lint            # eslint
npm run format:check    # prettier --check
npm run types:check     # vue-tsc --noEmit
npm run build           # vite build
```

Die GitHub-Actions-CI prüft dasselbe. **PRs werden nur mit grüner CI gemergt.**
Tipp: `npm run format:check` gehört fest zum lokalen Durchlauf – `composer check`
allein deckt das Frontend-Formatting nicht ab.

---

## Fragen?

Bei Fragen zur Architektur oder zum Vorgehen: siehe [`docs/architecture.md`](docs/architecture.md)
und die Roadmap unter [`docs/superpowers/plans/`](docs/superpowers/plans/).
