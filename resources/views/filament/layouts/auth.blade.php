@props([
    'livewire' => null,
])

@push('styles')
    <link
        rel="stylesheet"
        href="{{ asset('css/filament/admin/login.css') }}?v={{ filemtime(public_path('css/filament/admin/login.css')) }}"
    />
@endpush

<x-filament-panels::layout.base :livewire="$livewire">
    {{ $slot }}
</x-filament-panels::layout.base>
