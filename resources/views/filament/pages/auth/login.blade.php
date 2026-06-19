<div class="wm-login">
    <div class="wm-login__glow wm-login__glow--one" aria-hidden="true"></div>
    <div class="wm-login__glow wm-login__glow--two" aria-hidden="true"></div>

    <div class="wm-login__shell">
        <aside class="wm-login__showcase">
            <div class="wm-login__brand">
                <span class="wm-login__brand-mark" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 32 32" fill="none">
                        <path d="M6 10.25 16 5l10 5.25v11.5L16 27 6 21.75v-11.5Z" stroke="currentColor" stroke-width="2"/>
                        <path d="m6.5 10.5 9.5 5 9.5-5M16 15.5V27" stroke="currentColor" stroke-width="2"/>
                        <path d="m11 8 10 5.25v5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </span>
                <span>Warehouse Monitoring PT ISS</span>
            </div>

            <div class="wm-login__hero">
                <span class="wm-login__eyebrow">Operasional gudang</span>
                <h2>Operasional gudang dalam satu kendali.</h2>
                <p>
                    Pantau stok, kelola perpindahan barang, dan ambil keputusan lebih cepat
                    melalui dashboard yang terintegrasi.
                </p>

                <div class="wm-login__features" aria-label="Keunggulan sistem">
                    <div>
                        <x-filament::icon icon="heroicon-m-chart-bar-square" />
                        <span>Data real-time</span>
                    </div>
                    <div>
                        <x-filament::icon icon="heroicon-m-shield-check" />
                        <span>Akses terlindungi</span>
                    </div>
                    <div>
                        <x-filament::icon icon="heroicon-m-bolt" />
                        <span>Proses lebih cepat</span>
                    </div>
                </div>
            </div>

            <div class="wm-login__showcase-footer">
                <span class="wm-login__pulse"></span>
                Sistem siap digunakan
            </div>
        </aside>

        <main class="wm-login__main">
            <section class="wm-login__card">
                <div class="wm-login__mobile-brand">
                    <span class="wm-login__brand-mark" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 32 32" fill="none">
                            <path d="M6 10.25 16 5l10 5.25v11.5L16 27 6 21.75v-11.5Z" stroke="currentColor" stroke-width="2"/>
                            <path d="m6.5 10.5 9.5 5 9.5-5M16 15.5V27" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </span>
                    <span>Warehouse Monitoring PT ISS</span>
                </div>

                <header class="wm-login__header">
                    <span class="wm-login__welcome-icon" aria-hidden="true">
                        <x-filament::icon icon="heroicon-m-sparkles" />
                    </span>
                    <h1>{{ $this->getHeading() }}</h1>
                    <p>{{ $this->getSubheading() }}</p>
                </header>

                {{ \Filament\Support\Facades\FilamentView::renderHook(
                    \Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                    scopes: $this->getRenderHookScopes(),
                ) }}

                <x-filament-panels::form id="form" wire:submit="authenticate">
                    {{ $this->form }}

                    <x-filament-panels::form.actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="$this->hasFullWidthFormActions()"
                    />
                </x-filament-panels::form>

                {{ \Filament\Support\Facades\FilamentView::renderHook(
                    \Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                    scopes: $this->getRenderHookScopes(),
                ) }}

                <div class="wm-login__security">
                    <x-filament::icon icon="heroicon-m-lock-closed" />
                    <span>Koneksi aman &middot; Akses hanya untuk pengguna terdaftar</span>
                </div>
            </section>

            <footer class="wm-login__footer">
                &copy; {{ now()->year }} Warehouse Monitoring PT ISS. Seluruh hak dilindungi.
            </footer>
        </main>
    </div>

    <x-filament-actions::modals />
</div>
