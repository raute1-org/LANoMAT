<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('registration.self_checkin.print.title') }} – {{ $event->name }}</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: system-ui, sans-serif; color: #111; background: #fff; }
        main { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2rem; min-height: 100vh; padding: 2rem; box-sizing: border-box; text-align: center; }
        h1 { margin: 0; font-size: 2.75rem; line-height: 1.1; }
        p { margin: 0; font-size: 1.75rem; }
        .qr { width: min(80vw, 70vh, 34rem); }
        .qr svg { width: 100%; height: auto; display: block; }
        small { font-size: 0.9rem; color: #555; word-break: break-all; }
        button { font-size: 1rem; padding: 0.6rem 1.2rem; }
        @media print { button { display: none; } main { min-height: auto; } }
    </style>
</head>
<body>
<main>
    <h1>{{ $event->name }}</h1>
    <div class="qr">{!! $svg !!}</div>
    <p>{{ __('registration.self_checkin.print.instruction') }}</p>
    <small>{{ $url }}</small>
    <button type="button" onclick="window.print()">{{ __('registration.self_checkin.print.button') }}</button>
</main>
</body>
</html>
