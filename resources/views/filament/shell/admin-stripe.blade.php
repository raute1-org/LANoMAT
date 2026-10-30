{{-- Admin-area marker: thin neutral stripe across the top of every panel page (incl. login). Inline CSS: Filament does not compile app Tailwind. Neutral on purpose; amber stays reserved for primary action/live (docs/design.md). --}}
<style>
    .lanomat-admin-stripe { position: fixed; inset: 0 0 auto 0; height: 3px; z-index: 60; background: var(--gray-950); pointer-events: none; }
    .dark .lanomat-admin-stripe { background: var(--gray-50); }
</style>
<div class="lanomat-admin-stripe" aria-hidden="true" data-shell="admin-marker"></div>
