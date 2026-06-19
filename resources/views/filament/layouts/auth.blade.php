@props([
    'livewire' => null,
])

@push('styles')
    @vite('resources/css/filament/admin/login.css')
@endpush

<x-filament-panels::layout.base :livewire="$livewire">
    {{ $slot }}
</x-filament-panels::layout.base>
