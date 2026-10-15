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
                <path d="M12 3.5 13.7 8l4.5 1.7-4.5 1.7L12 16l-1.7-4.6L5.8 9.7 10.3 8 12 3.5Z" />
                <path d="M18.5 14.5l.8 2.1 2.2.8-2.2.8-.8 2.1-.8-2.1-2.2-.8 2.2-.8.8-2.1Z" />
                <path d="M5 15.5l.6 1.6 1.7.6-1.7.6L5 20l-.6-1.7L2.7 17.7l1.7-.6L5 15.5Z" />
            </svg>
        </span>
        <span class="wm-ai-topbar-btn__label">Xcode AI</span>
    </a>
@endif
