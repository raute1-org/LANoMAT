<?php

namespace App\Support\Brand;

use RuntimeException;

/**
 * The shell contract: everything the participant app (Inertia/Vue) and the
 * Filament admin panel must share so they read as one product.
 *
 * `public/logo-mark.svg` is the canonical logo geometry. The Filament brand view
 * derives from it at render time; the Vue component, favicon and the rest are
 * pinned to it by tests/Feature/ShellParity. See docs/design.md
 * "Two apps, one shell (parity rules)".
 */
final class ShellParity
{
    public const BRAND_NAME = 'LANoMAT';

    public const WORDMARK = 'LANoMAT';

    public const CANONICAL_MARK = 'public/logo-mark.svg';

    /** Badge next to the wordmark; present ONLY in the admin shell. */
    public const ADMIN_BADGE = 'Admin';

    /** Panel `brandLogoHeight` — the Filament side of the sidebar header height. */
    public const BRAND_LOGO_HEIGHT = '1.75rem';

    /** User menu sits in the sidebar footer in both apps (Filament: `UserMenuPosition::Sidebar`). */
    public const USER_MENU = 'sidebar';

    /** Sidebar collapses on desktop in both apps. */
    public const SIDEBAR_COLLAPSIBLE = true;

    /** German label of the Filament user-menu link back to the participant app. */
    public const FILAMENT_BACK_LABEL = 'Zur App';

    /** German labels of the Vue user menu (UserMenuContent.vue): admin link, settings, logout. */
    public const VUE_MENU_LABELS = ['Admin-Bereich', 'Einstellungen', 'Abmelden'];

    /**
     * Circles of the canonical mark in document order: the structure nodes
     * first, the amber signal node last.
     *
     * @return list<array{cx: string, cy: string, r: string}>
     */
    public static function markNodes(?string $svg = null): array
    {
        return self::circles($svg ?? self::read(self::CANONICAL_MARK));
    }

    /**
     * Extract `<circle cx cy r>` triples in document order from any SVG/Vue/Blade source.
     *
     * @return list<array{cx: string, cy: string, r: string}>
     */
    public static function circles(string $source): array
    {
        preg_match_all('/<circle\b[^>]*>/', $source, $tags);

        $nodes = [];

        foreach ($tags[0] as $tag) {
            $values = [];

            foreach (['cx', 'cy', 'r'] as $attribute) {
                if (! preg_match('/\s'.$attribute.'="([^"]+)"/', $tag, $m)) {
                    continue 2;
                }

                $values[$attribute] = $m[1];
            }

            $nodes[] = ['cx' => $values['cx'], 'cy' => $values['cy'], 'r' => $values['r']];
        }

        return $nodes;
    }

    public static function read(string $relativePath): string
    {
        $contents = @file_get_contents(base_path($relativePath));

        if ($contents === false) {
            throw new RuntimeException("Shell parity: cannot read {$relativePath}");
        }

        return $contents;
    }
}
