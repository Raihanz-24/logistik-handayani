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
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="4" y="7.5" width="16" height="12" rx="4" />
                <path d="M12 7.5V4.5" />
                <circle cx="12" cy="3.2" r="1.3" />
                <circle cx="9" cy="13" r="1.05" fill="currentColor" stroke="none" />
                <circle cx="15" cy="13" r="1.05" fill="currentColor" stroke="none" />
                <path d="M9.2 16.6h5.6" />
            </svg>
        </span>
        <span class="wm-ai-topbar-btn__label">Xcode AI</span>
    </a>
@endif
