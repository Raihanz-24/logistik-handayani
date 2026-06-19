<x-filament-panels::page class="warehouse-guide-page">
    <section class="wg-hero">
        <div class="wg-hero__orb wg-hero__orb--one"></div>
        <div class="wg-hero__orb wg-hero__orb--two"></div>

        <div class="wg-hero__content">
            <span class="wd-eyebrow">
                <x-filament::icon icon="heroicon-o-book-open" />
                Panduan cepat
            </span>

            <h1>
                Jalankan operasional gudang dengan alur yang rapi.
            </h1>

            <p>
                Ikuti panduan ini dari master data, mutasi masuk pengadaan, approve,
                stok terbentuk, mutasi keluar, hingga monitoring dashboard dan export
                laporan. Dibuat ringkas agar pengguna baru bisa langsung bekerja tanpa bingung.
            </p>

            <div class="wg-hero__meta">
                <span><i class="wd-pulse"></i> Warehouse Monitoring PT ISS</span>
                <span>Estimasi baca 5 menit</span>
            </div>
        </div>

        <div class="wg-hero__card">
            <span>Alur utama</span>
            <strong>Master data → Mutasi masuk → Approve → Stok terbentuk → Monitoring</strong>
            <p>Stok gudang bertambah setelah mutasi masuk pengadaan disetujui.</p>
        </div>
    </section>

    <section class="wg-block">
        <header class="wd-block-heading">
            <div>
                <span class="wd-kicker">Akses cepat</span>
                <h2>Mulai dari menu yang paling sering dipakai</h2>
                <p>Pilih pintasan sesuai pekerjaan yang ingin dilakukan.</p>
            </div>
        </header>

        <div class="wg-quick-grid">
            @foreach ($this->getQuickActions() as $action)
                <a href="{{ $action['url'] }}" class="wg-quick-card wg-quick-card--{{ $action['tone'] }}">
                    <span class="wg-quick-card__icon">
                        <x-filament::icon :icon="$action['icon']" />
                    </span>
                    <strong>{{ $action['label'] }}</strong>
                    <p>{{ $action['description'] }}</p>
                    <span class="wg-quick-card__link">
                        Buka menu
                        <x-filament::icon icon="heroicon-o-arrow-up-right" />
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="wg-layout">
        <div class="wg-timeline wd-panel">
            <header class="wd-panel__header">
                <div>
                    <span class="wd-kicker">Step by step</span>
                    <h2>Urutan penggunaan yang disarankan</h2>
                    <p>Ikuti dari atas ke bawah agar data stok dan mutasi tetap konsisten.</p>
                </div>

                <span class="wd-panel__badge">
                    <span class="wd-pulse"></span>
                    6 langkah
                </span>
            </header>

            <div class="wg-timeline__list">
                @foreach ($this->getWorkflowSteps() as $item)
                    <article class="wg-step">
                        <div class="wg-step__number">{{ $item['step'] }}</div>

                        <div class="wg-step__body">
                            <div class="wg-step__heading">
                                <span>
                                    <x-filament::icon :icon="$item['icon']" />
                                </span>
                                <div>
                                    <h3>{{ $item['title'] }}</h3>
                                    <p>{{ $item['description'] }}</p>
                                </div>
                            </div>

                            <ul>
                                @foreach ($item['points'] as $point)
                                    <li>{{ $point }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>

        <aside class="wg-side">
            <section class="wg-note">
                <span class="wg-note__icon">
                    <x-filament::icon icon="heroicon-o-light-bulb" />
                </span>
                <h2>Tips penting</h2>
                <p>
                    Jangan approve mutasi sebelum gudang, barang, jumlah, dan tujuan sudah benar.
                    Setelah approve, stok gudang akan berubah dan riwayat menjadi catatan operasional.
                </p>
            </section>

            <section class="wg-checklist">
                <span class="wd-kicker">Checklist harian</span>
                <h2>Sebelum menutup pekerjaan</h2>

                <div class="wg-checklist__items">
                    <span><x-filament::icon icon="heroicon-o-check-circle" /> Mutasi pending sudah ditinjau.</span>
                    <span><x-filament::icon icon="heroicon-o-check-circle" /> Pengadaan barang baru sudah dicatat sebagai mutasi masuk.</span>
                    <span><x-filament::icon icon="heroicon-o-check-circle" /> Stok rendah sudah dicek untuk rencana restock.</span>
                    <span><x-filament::icon icon="heroicon-o-check-circle" /> Filter dashboard sesuai periode laporan.</span>
                    <span><x-filament::icon icon="heroicon-o-check-circle" /> Export Excel dibuat bila dibutuhkan.</span>
                </div>
            </section>
        </aside>
    </section>

    <section class="wg-block">
        <header class="wd-block-heading">
            <div>
                <span class="wd-kicker">Peta menu</span>
                <h2>Fungsi tiap menu utama</h2>
                <p>Ringkasan singkat agar pengguna tahu harus membuka menu yang mana.</p>
            </div>
        </header>

        <div class="wg-module-grid">
            @foreach ($this->getModuleCards() as $module)
                <a href="{{ $module['url'] }}" class="wg-module-card">
                    <span class="wg-module-card__icon">
                        <x-filament::icon :icon="$module['icon']" />
                    </span>
                    <div>
                        <h3>{{ $module['title'] }}</h3>
                        <p>{{ $module['description'] }}</p>
                    </div>
                    <x-filament::icon class="wg-module-card__arrow" icon="heroicon-o-arrow-right" />
                </a>
            @endforeach
        </div>
    </section>
</x-filament-panels::page>
