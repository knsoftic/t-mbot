{{-- Label + control + hint + validation error for a Livewire property. --}}
@props(['label' => null, 'for' => null, 'hint' => null, 'required' => false, 'error' => null])
@php $errorKey = $error ?? $for; @endphp
<div {{ $attributes }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="label">
            {{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if ($hint)
        <p class="hint">{{ $hint }}</p>
    @endif
    @if ($errorKey)
        @error($errorKey) <p class="error">{{ $message }}</p> @enderror
    @endif
</div>
