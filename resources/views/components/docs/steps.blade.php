{{-- Numbered step list: <x-docs.steps><li>…</li></x-docs.steps> --}}
<ol {{ $attributes->merge(['class' => 'docs-steps space-y-3']) }}>
    {{ $slot }}
</ol>
