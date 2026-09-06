@auth
    @php
        $quickNavigationItems = [
            [
                'label' => 'Stok',
                'icon' => 'heroicon-o-archive-box',
                'url' => \App\Filament\Resources\BarangLokasiResource::getUrl('index'),
                'canAccess' => \App\Filament\Resources\BarangLokasiResource::canViewAny(),
                'exact' => false,
                'featured' => false,
            ],
            [
                'label' => 'Foto Maps',
                'icon' => 'heroicon-o-camera',
                'url' => \App\Filament\Pages\FotoBarangMaps::getUrl(),
                'canAccess' => \App\Filament\Pages\FotoBarangMaps::canAccess(),
                'exact' => false,
                'featured' => true,
            ],
            [
                'label' => 'Mutasi',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => \App\Filament\Resources\MutasiResource::getUrl('index'),
                'canAccess' => \App\Filament\Resources\MutasiResource::canViewAny(),
                'exact' => false,
                'featured' => false,
            ],
            [
                'label' => 'Dashboard',
                'icon' => 'heroicon-o-squares-2x2',
                'url' => \App\Filament\Pages\Dashboard::getUrl(),
                'canAccess' => \App\Filament\Pages\Dashboard::canAccess(),
                'exact' => true,
                'featured' => false,
            ],
        ];
    @endphp

    <div
        class="wm-mobile-nav-wrap"
        x-data="{
            currentPath: window.location.pathname,
            syncPath: null,
            init() {
                this.syncPath = () => this.currentPath = window.location.pathname;
                document.addEventListener('livewire:navigated', this.syncPath);
            },
            destroy() {
                if (this.syncPath) document.removeEventListener('livewire:navigated', this.syncPath);
            },
            isActive(url, exact = false) {
                const normalize = (path) => path.replace(/\/+$/, '') || '/';
                const current = normalize(this.currentPath);
                const target = normalize(new URL(url, window.location.origin).pathname);
                return exact ? current === target : current === target || current.startsWith(`${target}/`);
            },
        }"
        x-show="! $store.sidebar.isOpen"
        x-transition:enter="wm-mobile-nav-enter"
        x-transition:enter-start="wm-mobile-nav-enter-start"
        x-transition:enter-end="wm-mobile-nav-enter-end"
        x-transition:leave="wm-mobile-nav-leave"
        x-transition:leave-start="wm-mobile-nav-leave-start"
        x-transition:leave-end="wm-mobile-nav-leave-end"
    >
        <nav class="wm-mobile-nav" aria-label="Navigasi cepat">
            <button
                type="button"
                class="wm-mobile-nav__item"
                x-on:click="$store.sidebar.open()"
                aria-label="Buka menu utama"
            >
                <span class="wm-mobile-nav__icon"><x-filament::icon icon="heroicon-o-bars-3" /></span>
                <small>Menu</small>
            </button>

            @foreach ($quickNavigationItems as $item)
                @if ($item['canAccess'])
                    <a
                        href="{{ $item['url'] }}"
                        wire:navigate
                        @class([
                            'wm-mobile-nav__item',
                            'wm-mobile-nav__item--featured' => $item['featured'],
                        ])
                        x-bind:class="isActive($el.href, {{ $item['exact'] ? 'true' : 'false' }}) && 'is-active'"
                        x-bind:aria-current="isActive($el.href, {{ $item['exact'] ? 'true' : 'false' }}) ? 'page' : null"
                    >
                        <span class="wm-mobile-nav__icon"><x-filament::icon :icon="$item['icon']" /></span>
                        <small>{{ $item['label'] }}</small>
                    </a>
                @else
                    <span
                        @class([
                            'wm-mobile-nav__item',
                            'wm-mobile-nav__item--featured' => $item['featured'],
                            'is-disabled',
                        ])
                        aria-disabled="true"
                    >
                        <span class="wm-mobile-nav__icon"><x-filament::icon :icon="$item['icon']" /></span>
                        <small>{{ $item['label'] }}</small>
                    </span>
                @endif
            @endforeach
        </nav>
    </div>

@endauth
