<div class="wm-login">
    <div class="wm-login__glow wm-login__glow--one" aria-hidden="true"></div>
    <div class="wm-login__glow wm-login__glow--two" aria-hidden="true"></div>

    <div class="wm-login__shell">
        <aside class="wm-login__showcase">
            <div class="wm-login__liquid-art" aria-hidden="true">
                <span class="wm-login__liquid-orb wm-login__liquid-orb--gold"></span>
                <span class="wm-login__liquid-orb wm-login__liquid-orb--mint"></span>
                <span class="wm-login__liquid-orb wm-login__liquid-orb--blue"></span>
                <span class="wm-login__glass-ring"></span>
            </div>

            <div class="wm-login__brand">
                <span class="wm-login__brand-mark" aria-hidden="true">
                    <picture>
                        <source srcset="{{ asset('images/logo-handayani.webp') }}" type="image/webp">
                        <img src="{{ asset('images/logo-handayani.png') }}" alt="" width="768" height="768">
                    </picture>
                </span>
                <span class="wm-login__brand-copy">
                    <strong>Logistik Taman Air</strong>
                    <small>Handayani &middot; Paiton</small>
                </span>
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
                        <picture>
                            <source srcset="{{ asset('images/logo-handayani.webp') }}" type="image/webp">
                            <img src="{{ asset('images/logo-handayani.png') }}" alt="" width="768" height="768">
                        </picture>
                    </span>
                    <span class="wm-login__brand-copy">
                        <strong>Logistik Taman Air</strong>
                        <small>Handayani &middot; Paiton</small>
                    </span>
                </div>

                <header class="wm-login__header">
                    <h1>{{ $this->getHeading() }}</h1>
                    <p>{{ $this->getSubheading() }}</p>
                </header>

                {{ \Filament\Support\Facades\FilamentView::renderHook(
                    \Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                    scopes: $this->getRenderHookScopes(),
                ) }}

                <form class="wm-login__form" wire:submit.prevent="authenticate" novalidate>
                    <div
                        @class([
                            'wm-login__form-alert',
                            'wm-login__form-alert--visible' => filled($this->loginErrorMessage),
                        ])
                        aria-live="polite"
                    >
                        @if ($this->loginErrorMessage)
                            <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                            <span>{{ $this->loginErrorMessage }}</span>
                        @endif
                    </div>

                    <label class="wm-login__field" for="username">
                        <span class="wm-login__label">Username</span>
                        <span class="wm-login__input-wrap">
                            <x-filament::icon class="wm-login__input-icon" icon="heroicon-m-user" />
                            <input
                                id="username"
                                type="text"
                                inputmode="text"
                                autocomplete="username"
                                autofocus
                                tabindex="1"
                                wire:model="data.username"
                                placeholder="Masukkan username"
                                class="wm-login__input"
                            >
                        </span>
                    </label>

                    <label class="wm-login__field" for="password" x-data="{ showPassword: false }">
                        <span class="wm-login__label">Kata sandi</span>
                        <span class="wm-login__input-wrap">
                            <x-filament::icon class="wm-login__input-icon" icon="heroicon-m-lock-closed" />
                            <input
                                id="password"
                                x-bind:type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                tabindex="2"
                                wire:model="data.password"
                                placeholder="Masukkan kata sandi"
                                class="wm-login__input"
                            >
                            <button
                                type="button"
                                class="wm-login__reveal"
                                x-on:click="showPassword = ! showPassword"
                                x-bind:aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                                tabindex="4"
                            >
                                <x-filament::icon x-show="! showPassword" icon="heroicon-m-eye" />
                                <x-filament::icon x-cloak x-show="showPassword" icon="heroicon-m-eye-slash" />
                            </button>
                        </span>
                    </label>

                    <label class="wm-login__remember">
                        <input
                            type="checkbox"
                            wire:model="data.remember"
                            tabindex="3"
                            class="wm-login__checkbox"
                        >
                        <span>Ingat saya di perangkat ini</span>
                    </label>

                    <button
                        type="submit"
                        class="wm-login__submit"
                        wire:loading.attr="disabled"
                        wire:target="authenticate"
                    >
                        <x-filament::icon class="wm-login__submit-icon" icon="heroicon-m-arrow-right-end-on-rectangle" />
                        <span wire:loading.remove wire:target="authenticate">Masuk ke dashboard</span>
                        <span wire:loading wire:target="authenticate">Memproses...</span>
                    </button>
                </form>

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
                <span class="wm-login__copyright">
                    &copy; {{ now()->year }} Logistik Taman Air Handayani Paiton. Seluruh hak dilindungi.
                </span>
                <a
                    class="wm-login__credit"
                    href="https://kafeinxcode.com"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    by <strong>kafeinxcode.com</strong>
                </a>
            </footer>
        </main>
    </div>

    <x-filament-actions::modals />
</div>
