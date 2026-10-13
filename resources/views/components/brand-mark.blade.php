{{-- The uploaded logo (Settings → General) or the default paper-plane mark. --}}
@props(['size' => 'size-9'])
@php $logo = app(\App\Services\SettingsService::class)->get('general.logo_path'); @endphp
@if ($logo)
    <img src="{{ route('branding.logo') }}?v={{ md5($logo) }}" alt="{{ config('app.name') }}" {{ $attributes->merge(['class' => $size.' shrink-0 rounded-xl object-contain']) }}>
@else
    <div {{ $attributes->merge(['class' => $size.' flex shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white shadow-lg shadow-brand-600/30']) }}>
        <x-icon name="send" class="size-1/2 -rotate-12" />
    </div>
@endif
