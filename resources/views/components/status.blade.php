{{-- Renders any enum that has label() and color() as a badge. --}}
@props(['value', 'dot' => true])
@if ($value instanceof \BackedEnum)
    <x-badge :color="$value->color()" :dot="$dot" {{ $attributes }}>{{ $value->label() }}</x-badge>
@else
    <x-badge color="slate" {{ $attributes }}>{{ $value ?? '—' }}</x-badge>
@endif
