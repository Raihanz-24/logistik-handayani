@php
    use App\Filament\Pages\AsistenAi;

    $aiUrl = null;
    $aiAccessible = false;

    try {
        $aiAccessible = AsistenAi::canAccess();
        $aiUrl = AsistenAi::getUrl();
    } catch (\Throwable) {
        $aiAccessible = false;
    }
@endphp

@if ($aiAccessible && filled($aiUrl))
    <a
        href="{{ $aiUrl }}"
        wire:navigate.hover
        class="wm-ai-topbar-btn {{ request()->routeIs('filament.admin.pages.asisten-ai') ? 'is-active' : '' }}"
        title="Buka Asisten AI (Xcode AI)"
        aria-label="Buka Asisten AI Xcode AI"
    >
        <span class="wm-ai-topbar-btn__icon" aria-hidden="true">
            <img
                src="{{ asset('images/logo-ai.webp') }}"
                alt=""
                width="216"
                height="202"
                loading="lazy"
                decoding="async"
            >
        </span>
        <span class="wm-ai-topbar-btn__label">Xcode AI</span>
    </a>
@endif
