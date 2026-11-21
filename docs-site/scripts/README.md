# Docs scripts

Three small Node scripts back the docs build and its screenshots. None of them is part of the
Zudoku bundle.

| Script | `npm` | What it does |
|---|---|---|
| `check-links.mjs` | `npm run check:links` | Fails on broken internal links, nav entries and missing `/screens/...` assets |
| `copy-screenshots.mjs` | `npm run screenshots` | Copies the app's `docs/screenshots/` set into `public/screens/` |
| `capture-docs.mjs` | — | Screenshots the built docs site itself (the "docs" surface in the tour) |

## Capturing the docs themselves

`capture-docs.mjs` needs a full checkout: Playwright comes from the app's root `devDependencies`,
not from `docs-site/node_modules`. After `npm run build`:

```bash
python3 -m http.server 4013 --directory dist &
# A plain file server has no clean-URL rewrite; the script falls back to
# prerendered .html files, so extensionless paths still work.
DOCS_URL=http://127.0.0.1:4013 node scripts/capture-docs.mjs
```

It writes `public/screens/docs-games-library-{light,dark}.png` and
`docs-mcp-{light,dark}.png`, scrolled to the screenshot tables so the embedded app screenshots are
visible. Run `npm run build` again afterwards so the new files land in `dist/`.

The app screenshots themselves come from `scripts/screenshots/capture.mjs` in the app checkout
(seeded `ScreenshotSeeder` + the fixed `DEMO-2345` pairing code); that script writes the games,
game detail, MCP connect and settings screenshots into `docs/screenshots/`, which
`copy-screenshots.mjs` then copies here.

## Seed state the docs shots rely on

`ScreenshotSeeder` covers the event pages and one published recipe (UT2004). Two extra states make
the docs screenshots representative:

```bash
# A catalogue worth looking at: sync the bundled recipes, then publish a few.
php artisan lanomat:recipes:sync
php artisan tinker   # update(['status' => 'published']) on the slugs you want to show

# A pending pairing request with the fixed user code the capture script uses.
php artisan tinker
>>> (new App\Modules\Mcp\Models\McpDeviceCode)->forceFill([
...   'device_code_hash' => App\Modules\Mcp\Support\McpCodes::hash('docs-device'),
...   'user_code_hash' => App\Modules\Mcp\Support\McpCodes::hash('DEMO2345'),
...   'device_name' => 'pi · demo-laptop',
...   'status' => App\Modules\Mcp\Models\McpDeviceCode::PENDING,
...   'interval' => 5,
...   'expires_at' => now()->addMinutes(15),
... ])->save();
```

`/mcp/connect?code=DEMO-2345` then renders the approval card; without the row it renders the
"invalid code" state. A couple of `McpToken` rows for the same user fill the "connected agents"
list.
