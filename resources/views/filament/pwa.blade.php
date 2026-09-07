@php
    $pwaAppAssets = [
        \Illuminate\Support\Facades\Vite::asset('resources/css/filament-dashboard.css'),
        \Illuminate\Support\Facades\Vite::asset('resources/js/pwa-navigation-skeleton.js'),
        \Illuminate\Support\Facades\Vite::asset('resources/js/mobile-swipe-navigation.js'),
        \Illuminate\Support\Facades\Vite::asset('resources/js/foto-barang-maps.js'),
        \Illuminate\Support\Facades\Vite::asset('resources/js/foto-barang-folder.js'),
        asset('images/logo-handayani.webp'),
    ];
@endphp

@auth
    @vite('resources/js/pwa-navigation-skeleton.js')
@endauth

<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="application-name" content="Logistik Handayani">
<meta name="theme-color" content="#102031">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Logistik Handayani">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/pwa/apple-touch-icon.png') }}">

<script>
    (() => {
        if (window.__handayaniPwaInitialized) {
            window.__handayaniCachePwaAssets?.();

            return;
        }

        window.__handayaniPwaInitialized = true;

        const displayMode = window.matchMedia('(display-mode: standalone)');
        const isInstalledPwa = () => displayMode.matches || window.navigator.standalone === true;
        const declaredAppAssets = @js($pwaAppAssets);
        let installPrompt = null;

        const cachePwaAppAssets = () => {
            if (! isInstalledPwa() || ! ('serviceWorker' in navigator)) {
                return;
            }

            const documentAssets = Array.from(document.querySelectorAll('link[rel="stylesheet"][href], script[src]'))
                .map((element) => element.href || element.src)
                .filter(Boolean);

            navigator.serviceWorker.ready.then((registration) => {
                registration.active?.postMessage({
                    type: 'CACHE_APP_ASSETS',
                    urls: [...new Set([...declaredAppAssets, ...documentAssets])],
                });
            }).catch(() => {
                // PWA remains usable when asset storage is unavailable or denied.
            });
        };

        window.__handayaniCachePwaAssets = cachePwaAppAssets;

        const updateInstallButton = () => {
            const button = document.querySelector('[data-pwa-install]');

            if (! button) {
                return;
            }

            button.hidden = isInstalledPwa();
        };

        const showInstallHelp = (message) => {
            const help = document.querySelector('[data-pwa-install-help]');

            if (! help) {
                return;
            }

            help.textContent = message;
            help.hidden = false;
        };

        if (isInstalledPwa()) {
            const viewport = document.querySelector('meta[name="viewport"]');

            viewport?.setAttribute(
                'content',
                'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover',
            );

            document.documentElement.classList.add('is-installed-pwa');

            document.addEventListener('gesturestart', (event) => event.preventDefault(), { passive: false });
            document.addEventListener('gesturechange', (event) => event.preventDefault(), { passive: false });
            document.addEventListener('touchmove', (event) => {
                if (event.touches.length > 1) {
                    event.preventDefault();
                }
            }, { passive: false });

            window.addEventListener('wheel', (event) => {
                if (event.ctrlKey) {
                    event.preventDefault();
                }
            }, { passive: false });

            window.addEventListener('keydown', (event) => {
                if (event.ctrlKey && ['+', '-', '=', '0'].includes(event.key)) {
                    event.preventDefault();
                }
            });
        }

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            installPrompt = event;
            updateInstallButton();
        });

        window.addEventListener('appinstalled', () => {
            installPrompt = null;
            updateInstallButton();
            cachePwaAppAssets();
        });

        displayMode.addEventListener?.('change', updateInstallButton);

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-pwa-install]');

            if (! button) {
                return;
            }

            if (installPrompt) {
                button.disabled = true;
                await installPrompt.prompt();
                const choice = await installPrompt.userChoice;
                installPrompt = null;
                button.disabled = false;

                if (choice.outcome === 'accepted') {
                    button.hidden = true;
                    showInstallHelp('Aplikasi sedang dipasang di perangkat Anda.');
                }

                return;
            }

            const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

            showInstallHelp(isIos
                ? 'Ketuk Bagikan, lalu pilih Tambahkan ke Layar Utama.'
                : 'Buka menu browser, lalu pilih Instal aplikasi atau Tambahkan ke layar utama.');
        });

        document.addEventListener('DOMContentLoaded', updateInstallButton, { once: true });
        document.addEventListener('livewire:navigated', () => {
            updateInstallButton();
            cachePwaAppAssets();
        });

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('{{ asset('service-worker.js') }}', {
                    scope: '/',
                    updateViaCache: 'none',
                }).then((registration) => {
                    registration.update();
                    cachePwaAppAssets();
                }).catch(() => {
                    // Aplikasi tetap dapat digunakan normal jika browser menolak PWA.
                });
            }, { once: true });
        }
    })();
</script>
