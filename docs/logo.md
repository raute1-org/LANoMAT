# LANoMAT logo

Mark: an **L built from four nodes, the fifth node (the foot tip) lit amber**. L for LAN; the nodes are machines or seats on a desk; the one amber node is the live signal - the same single rationed accent as in [design.md](design.md). Abstract, geometric, no literal symbols.

Wordmark: `LANoMAT` (exact spelling), Space Grotesk semibold, set beside the mark.

## Geometry

24x24 viewBox, node radius 2.6 (= 1x), centres on a 7.5 pitch at 4.5 / 12 / 19.5. Structure nodes: (4.5,4.5) (4.5,12) (4.5,19.5) (12,19.5). Amber node: (19.5,19.5).

## Files

| File                                       | Use                                                                              |
| ------------------------------------------ | -------------------------------------------------------------------------------- |
| `resources/js/components/AppLogoIcon.vue`  | Mark as inline SVG. Structure `currentColor`, amber node `fill-primary` (token). |
| `resources/js/components/AppLogo.vue`      | Mark + wordmark, sidebar header (no container behind the mark).                  |
| `public/logo-mark.svg`                     | Standalone mark, light/dark via `prefers-color-scheme`.                          |
| `public/favicon.svg`                       | Same as `logo-mark.svg`.                                                         |
| `public/favicon.ico`                       | 16/32/48, light mark + bright amber, transparent (reads on dark and mid tabs).   |
| `public/apple-touch-icon.png`              | 180x180, opaque `--graphite-950` (`#0f1215`), mark at 60%.                       |
| `resources/views/filament/brand.blade.php` | Filament panel brand (mark + wordmark); favicon set in `AdminPanelProvider`.     |

## Rules

| Topic        | Rule                                                                                                            |
| ------------ | --------------------------------------------------------------------------------------------------------------- |
| Clear space  | 1x (one node diameter, 5.2 units = 22% of mark height) on all sides.                                            |
| Minimum size | Mark alone 16px; mark with wordmark 24px mark height.                                                           |
| One-colour   | All five nodes `currentColor`. Use where amber is unavailable (print, mono).                                    |
| Amber        | Light bg: `--primary` (`#a85a00`, AA). Dark bg: bright amber `#ffb020`. Always via token, never raw hex in Vue. |
| Backgrounds  | `--background`, `--sidebar`, `--graphite-950`, `--paper-50`. Not on photos or amber fills.                      |

Do: keep proportions; keep exactly one amber node; place it bottom-right. Don't: add a container, outline, gradient or shadow; recolour more than one node; rotate or reorder nodes; use the stock Laravel glyph.

## Regenerate raster assets

Light-on-dark variant of the SVG (strip the `<style>` block, set fills `#f2f4f6` and `#ffb020`), here `/tmp/mark-dark.svg`:

```bash
for s in 16 32 48; do rsvg-convert -w $s -h $s /tmp/mark-dark.svg -o /tmp/ico-$s.png; done
magick /tmp/ico-16.png /tmp/ico-32.png /tmp/ico-48.png public/favicon.ico
rsvg-convert -w 108 -h 108 /tmp/mark-dark.svg -o /tmp/at-mark.png
magick -size 180x180 xc:'#0f1215' /tmp/at-mark.png -gravity center -composite -alpha off -depth 8 PNG24:public/apple-touch-icon.png
```
