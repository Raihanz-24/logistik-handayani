@auth
    @vite('resources/js/pwa-navigation-skeleton.js')

    <div
        class="wm-navigation-skeleton"
        data-pwa-navigation-skeleton
        hidden
        aria-hidden="true"
    >
        <div class="wm-navigation-skeleton__content" aria-label="Memuat halaman">
            <div class="wm-navigation-skeleton__heading">
                <span class="wm-navigation-skeleton__line wm-navigation-skeleton__line--eyebrow"></span>
                <span class="wm-navigation-skeleton__line wm-navigation-skeleton__line--title"></span>
            </div>

            <div class="wm-navigation-skeleton__cards" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>

            <div class="wm-navigation-skeleton__panel" aria-hidden="true">
                <div class="wm-navigation-skeleton__toolbar">
                    <span></span>
                    <span></span>
                </div>

                <div class="wm-navigation-skeleton__rows">
                    @for ($row = 0; $row < 6; $row++)
                        <span style="--skeleton-row: {{ $row }}"></span>
                    @endfor
                </div>
            </div>
        </div>
    </div>
@endauth
