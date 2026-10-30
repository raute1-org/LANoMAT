{{-- Shared shell for the German error pages (Signalpult look, docs/design.md).
     Deliberately static: no session, no DB, no exception details, so it also
     renders for 503 maintenance and for errors raised before the web
     middleware group ran. Tokens/dark mode come from the compiled app.css. --}}
@php($code = (int) $code)
<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{{ __("errors.{$code}.title") }} – {{ config('app.name', 'LANoMAT') }}</title>

        <script>
            (function () {
                var match = document.cookie.match(/(?:^|; )appearance=(light|dark|system)/);
                var appearance = match ? match[1] : 'system';
                if (appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>
        <style>
            html { background-color: oklch(1 0 0); }
            html.dark { background-color: oklch(0.145 0 0); }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @if (file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json')))
            @fonts
            @vite(['resources/css/app.css'])
        @endif
    </head>
    <body class="bg-background font-sans text-foreground antialiased">
        <main class="mx-auto flex min-h-svh max-w-xl flex-col justify-center px-4 py-8 sm:px-6">
            <div class="flex items-center gap-2" aria-label="LANoMAT">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="size-7 text-foreground" aria-hidden="true">
                    <g fill="currentColor">
                        <circle cx="5.8" cy="2.7" r="2.5" />
                        <circle cx="5.8" cy="8.9" r="2.5" />
                        <circle cx="5.8" cy="15.1" r="2.5" />
                        <circle cx="5.8" cy="21.3" r="2.5" />
                        <circle cx="12" cy="21.3" r="2.5" />
                    </g>
                    <circle class="fill-primary" cx="18.2" cy="21.3" r="2.5" />
                </svg>
                <span class="font-semibold tracking-tight">LANoMAT</span>
            </div>

            <section class="mt-10" aria-labelledby="error-title">
                <p class="font-mono text-sm tabular-nums text-muted-foreground">{{ __('errors.status', ['code' => $code]) }}</p>
                <h1 id="error-title" class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">{{ __("errors.{$code}.title") }}</h1>
                <p class="mt-3 text-base text-muted-foreground">{{ __("errors.{$code}.message") }}</p>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <a href="{{ url('/') }}" class="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none">{{ __('errors.home') }}</a>
                    @if (request()->is('admin', 'admin/*') && (auth()->user()?->isOrga() ?? false))
                        <a href="{{ url('/admin') }}" class="inline-flex h-9 items-center rounded-md border border-border px-4 text-sm font-medium transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none">{{ __('errors.admin_home') }}</a>
                    @endif
                </div>
            </section>
        </main>
    </body>
</html>
