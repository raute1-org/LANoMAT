@php /** @var string $svg */ /** @var string $url */ @endphp
<div style="display:flex;flex-direction:column;align-items:center;gap:1rem;">
    <div style="width:min(100%,18rem);background:#fff;padding:0.75rem;border-radius:0.5rem;">
        <div style="width:100%;">{!! $svg !!}</div>
    </div>
    <code style="word-break:break-all;text-align:center;font-size:0.8rem;">{{ $url }}</code>
</div>
