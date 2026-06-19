<x-filament-widgets::widget>
    <section class="wd-hero">
        <div class="wd-hero__orb wd-hero__orb--one"></div>
        <div class="wd-hero__orb wd-hero__orb--two"></div>

        <div class="wd-hero__content">
            <span class="wd-eyebrow">
                <x-filament::icon icon="heroicon-m-sparkles" />
                Dashboard operasional
            </span>

            <h1>{{ $greeting }}, <strong>{{ $firstName }}</strong></h1>
            <p>
                Pantau kondisi stok, aktivitas mutasi, dan prioritas restock gudang
                dalam satu tampilan terpadu.
            </p>

            <div class="wd-system-status">
                <span></span>
                Sistem siap digunakan
            </div>
        </div>

        <div class="wd-hero__metrics">
            <article class="wd-hero-metric">
                <span class="wd-hero-metric__icon">
                    <x-filament::icon icon="heroicon-o-clock" />
                </span>
                <div>
                    <small>Waktu saat ini</small>
                    <strong>{{ $currentTime }}</strong>
                    <span>{{ $currentDate }}</span>
                </div>
            </article>

            <article class="wd-hero-metric">
                <span class="wd-hero-metric__icon wd-hero-metric__icon--green">
                    <x-filament::icon icon="heroicon-o-archive-box" />
                </span>
                <div>
                    <small>Kondisi stok</small>
                    <strong>{{ number_format($totalStock) }} unit</strong>
                    <span>{{ $activeLocations }} gudang · {{ $pendingMutations }} mutasi menunggu</span>
                </div>
            </article>
        </div>
    </section>
</x-filament-widgets::widget>
