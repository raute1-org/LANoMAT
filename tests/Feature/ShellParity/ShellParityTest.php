<?php

/**
 * Guard: the participant app (Inertia/Vue) and the Filament admin panel must look
 * and behave like one product. A shell/branding change that lands in only one
 * stack fails here. Rules: docs/design.md "Two apps, one shell (parity rules)".
 *
 * Expected values are deliberately literals (not ShellParity constants) so that
 * changing the contract requires touching this test as well.
 */

use App\Models\User;
use App\Support\Brand\ShellParity;
use App\Support\Modules\Modules;
use Filament\Enums\UserMenuPosition;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\View;

$canonical = fn (): array => ShellParity::markNodes();

it('has the canonical L-mark: five structure nodes plus one amber node', function () use ($canonical) {
    expect($canonical())->toBe([
        ['cx' => '5.8', 'cy' => '2.7', 'r' => '2.5'],
        ['cx' => '5.8', 'cy' => '8.9', 'r' => '2.5'],
        ['cx' => '5.8', 'cy' => '15.1', 'r' => '2.5'],
        ['cx' => '5.8', 'cy' => '21.3', 'r' => '2.5'],
        ['cx' => '12', 'cy' => '21.3', 'r' => '2.5'],
        ['cx' => '18.2', 'cy' => '21.3', 'r' => '2.5'],
    ]);
});

it('keeps every logo copy identical to the canonical geometry', function (string $file) use ($canonical) {
    expect(ShellParity::circles(ShellParity::read($file)))->toBe($canonical());
})->with([
    'Vue mark' => 'resources/js/components/AppLogoIcon.vue',
    'favicon' => 'public/favicon.svg',
]);

it('renders the Filament brand view with the canonical geometry and wordmark', function () use ($canonical) {
    $html = View::make('filament.brand')->render();

    expect(ShellParity::circles($html))->toBe($canonical())
        ->and($html)->toContain('>LANoMAT</span>');
});

it('wires the Filament panel to the shell contract', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->make());
    $panel = Filament::getPanel('admin');

    expect($panel->getBrandName())->toBe('LANoMAT')
        ->and($panel->getBrandLogoHeight())->not->toBeEmpty()
        ->and($panel->getUserMenuPosition())->toBe(UserMenuPosition::Sidebar)
        ->and($panel->isSidebarCollapsibleOnDesktop())->toBeTrue()
        ->and($panel->getUserMenuItems())->toHaveKey('back-to-app')
        ->and($panel->getUserMenuItems()['back-to-app']->getLabel())->toBe('Zur App');
});

it('keeps the participant user menu labels (Vue side of the shell contract)', function () {
    $menu = ShellParity::read('resources/js/components/UserMenuContent.vue');

    foreach (['Admin-Bereich', 'Einstellungen', 'Abmelden'] as $label) {
        expect($menu)->toContain($label);
    }
});

it('uses the same wordmark in the Vue logo', function () {
    expect(ShellParity::read('resources/js/components/AppLogo.vue'))->toContain('LANoMAT');
});

/*
 * Heuristic (documented in docs/design.md): Filament does not compile the app's
 * Tailwind, so utility classes in resources/views/filament/** silently do nothing.
 * Only `fi-*` classes, Filament components and inline styles are allowed.
 */
it('does not use app-only Tailwind utilities in Filament views', function () {
    $utility = '/^(?:[a-z-]+:)*(?:-?(?:h|w|gap|p[xytblr]?|m[xytblr]?|space-[xy]|text|bg|border|rounded|font|size|min-[hw]|max-[hw]|leading|tracking|items|justify|shadow|ring|opacity)-\S+|flex|grid|hidden|block|inline-flex|truncate|uppercase|italic)$/';

    $offenders = [];

    foreach (glob(base_path('resources/views/filament').'/{*.blade.php,*/*.blade.php}', GLOB_BRACE) ?: [] as $path) {
        preg_match_all('/\sclass="([^"]*)"/', (string) file_get_contents($path), $attrs);

        foreach ($attrs[1] as $value) {
            // Blade echoes inside class values are ignored; we only judge literal tokens.
            $value = (string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $value);

            foreach (preg_split('/\s+/', trim($value)) ?: [] as $token) {
                if ($token === '' || str_starts_with($token, 'fi-')) {
                    continue;
                }

                if (preg_match($utility, $token)) {
                    $offenders[] = basename($path).": {$token}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('marks the admin area (badge + stripe) and offers an always-visible way back', function () {
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    expect(View::make('filament.brand')->render())->toContain('data-shell="admin-badge"')->toContain('>Admin</span>')
        ->and(FilamentView::renderHook(PanelsRenderHook::BODY_START)->toHtml())->toContain('data-shell="admin-marker"')
        ->and($back = FilamentView::renderHook(PanelsRenderHook::TOPBAR_END)->toHtml())->toContain('data-shell="back-to-app"')
        ->and($back)->toContain('href="/"')
        ->and($back)->toContain('Zur App');
});

it('offers the desktop app in both shells under the same label and URL', function () {
    // Participant sidebar (Vue) ...
    $sidebar = ShellParity::read('resources/js/components/AppSidebar.vue');
    expect($sidebar)->toContain("title: 'Desktop-App'")->toContain("has('web_voice')");

    // ... and the Filament navigation item, visible while the WebVoice module has routes.
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->make());
    $items = collect(Filament::getPanel('admin')->getNavigationItems())
        ->filter(fn ($item) => $item->getLabel() === 'Desktop-App');

    expect($items)->toHaveCount(1)
        ->and($items->first()->getUrl())->toBe('/app')
        ->and($items->first()->isVisible())->toBeTrue()
        ->and(route('client.download.short', absolute: false))->toBe('/app');
});

it('hides the Filament desktop-app item while the WebVoice module is off', function () {
    $this->app->instance(Modules::class, new Modules('full', ['web_voice' => false]));

    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->make());
    $item = collect(Filament::getPanel('admin')->getNavigationItems())
        ->first(fn ($item) => $item->getLabel() === 'Desktop-App');

    expect($item)->not->toBeNull()->and($item->isVisible())->toBeFalse();
});

it('keeps the admin marker out of the participant app', function () {
    foreach (['AppLogo.vue', 'AppLogoIcon.vue', 'AppSidebar.vue'] as $file) {
        $source = ShellParity::read('resources/js/components/'.$file);

        expect($source)->not->toContain('data-shell="admin')->not->toContain('>Admin<');
    }
});
