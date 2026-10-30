{{--
    Filament does not compile the app's Tailwind utilities, so sizing/layout is inline.
    Geometry is NOT written here: it derives from the canonical public/logo-mark.svg via
    App\Support\Brand\ShellParity (parity-tested against AppLogoIcon.vue and favicon.svg).
--}}
@php
    $nodes = \App\Support\Brand\ShellParity::markNodes();
    $signal = array_pop($nodes);
@endphp
<span style="display: inline-flex; align-items: center; gap: 0.5rem; height: 100%; font-weight: 600; letter-spacing: -0.025em; font-size: 1.125rem; line-height: 1;">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" style="flex: none; width: 1.5rem; height: 1.5rem;" aria-hidden="true">
        <g fill="currentColor">
            @foreach ($nodes as $node)
                <circle cx="{{ $node['cx'] }}" cy="{{ $node['cy'] }}" r="{{ $node['r'] }}" />
            @endforeach
        </g>
        <circle cx="{{ $signal['cx'] }}" cy="{{ $signal['cy'] }}" r="{{ $signal['r'] }}" style="fill: var(--primary-500);" />
    </svg>
    <span>{{ \App\Support\Brand\ShellParity::WORDMARK }}</span>
    {{-- Admin badge: neutral high-contrast pill (inverts in dark mode), present only in the admin shell. --}}
    <style>
        .lanomat-admin-badge { background: var(--gray-950); color: var(--gray-50); }
        .dark .lanomat-admin-badge { background: var(--gray-50); color: var(--gray-950); }
    </style>
    <span class="lanomat-admin-badge" data-shell="admin-badge" style="font-size: 0.625rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; padding: 0.2rem 0.45rem; border-radius: 0.25rem; line-height: 1;">{{ \App\Support\Brand\ShellParity::ADMIN_BADGE }}</span>
</span>
