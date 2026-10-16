<x-filament-panels::page>
    @php
        $stats = $this->recognitionStats();
        $patterns = $this->supplierPatterns();
        $recent = $this->recentSearches();
        $ambiguous = $this->ambiguousKeywords();
    @endphp

    <div class="kai-page">
        <section class="kai-hero">
            <div>
                <span class="kai-hero__eyebrow">Penilaian asisten</span>
                <h1>Seberapa pintar AI saat ini?</h1>
                <p>Ringkasan kemampuan Asisten mengenali <strong>barang</strong> dan <strong>pola urutan item</strong> per supplier. Angka dihitung dari data nyata (log pencarian & histori transaksi), bukan perkiraan.</p>
            </div>
        </section>

        {{-- Kartu pengenalan barang --}}
        <section class="kai-cards">
            <div class="kai-card">
                <span class="kai-card__label">Pengenalan barang</span>
                <span class="kai-card__value">{{ $stats['akurasi'] }}%</span>
                <span class="kai-card__hint">{{ $stats['tepat'] }} dari {{ $stats['total'] }} pencarian langsung tepat</span>
            </div>
            <div class="kai-card">
                <span class="kai-card__label">Pencarian ambigu</span>
                <span class="kai-card__value">{{ $stats['ambigu'] }}</span>
                <span class="kai-card__hint">perlu ditanya / ditinjau</span>
            </div>
            <div class="kai-card">
                <span class="kai-card__label">Supplier ber-pola</span>
                <span class="kai-card__value">{{ count($patterns) }}</span>
                <span class="kai-card__hint">punya pola urutan terpelajar</span>
            </div>
        </section>

        {{-- Pola urutan per supplier --}}
        <section class="kai-section">
            <h2 class="kai-section__title">Pola urutan item per supplier</h2>
            <p class="kai-section__desc">Pola terbentuk setelah histori cukup. Saat menyusun draft, item yang tidak dibeli cukup <em>dilompati</em> — sisanya tetap urut seperti kebiasaan.</p>

            @forelse ($patterns as $supplier)
                <div class="kai-supplier">
                    <div class="kai-supplier__head">
                        <strong>{{ $supplier['nama'] }}</strong>
                        <span class="kai-badge {{ $supplier['keyakinan'] >= 60 ? 'kai-badge--ok' : 'kai-badge--warn' }}">
                            keyakinan {{ $supplier['keyakinan'] }}%
                        </span>
                    </div>

                    @if (empty($supplier['pola']))
                        <span class="kai-muted">Belum ada pola kuat (butuh ≥ 2 transaksi konsisten).</span>
                    @else
                        <ol class="kai-flow">
                            @foreach ($supplier['pola'] as $item)
                                <li>
                                    <span class="kai-flow__name">{{ $item['nama_barang'] }}</span>
                                    <span class="kai-flow__meta">baris~{{ number_format($item['avg_urutan'] + 1, 1, ',', '.') }} · {{ $item['frekuensi'] }}×</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            @empty
                <span class="kai-muted">Belum ada data. Catat beberapa transaksi belanja lalu jalankan <code>php artisan ai:learn</code>.</span>
            @endforelse
        </section>

        {{-- Pencarian ambigu yang sering muncul --}}
        <section class="kai-section">
            <h2 class="kai-section__title">Perlu perhatian: kata yang sering ambigu</h2>
            <p class="kai-section__desc">Kata ini paling sering membuat AI ragu. Tambahkan alias/persempit sebutannya agar makin pintar.</p>

            @if ($ambiguous->isEmpty())
                <span class="kai-muted">Tidak ada pencarian ambigu. Bagus! 🎉</span>
            @else
                <div class="kai-chips">
                    @foreach ($ambiguous as $row)
                        <span class="kai-chip">{{ $row->kata_kunci }} <b>{{ (int) $row->jumlah }}×</b></span>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Pencarian terbaru --}}
        <section class="kai-section">
            <h2 class="kai-section__title">Pencarian terbaru</h2>
            <div class="kai-table-wrap">
                <table class="kai-table">
                    <thead>
                        <tr>
                            <th>Kata kunci</th>
                            <th>Hasil terbaik</th>
                            <th class="kai-right">Kandidat</th>
                            <th class="kai-right">Skor</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recent as $log)
                            <tr>
                                <td>{{ $log->kata_kunci }}</td>
                                <td>{{ $log->barang?->nama_barang ?? '—' }}</td>
                                <td class="kai-right">{{ $log->jumlah_kandidat }}</td>
                                <td class="kai-right">{{ $log->skor_teratas }}</td>
                                <td>
                                    @if ($log->ambigu)
                                        <span class="kai-badge kai-badge--warn">ambigu</span>
                                    @else
                                        <span class="kai-badge kai-badge--ok">tepat</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="kai-muted">Belum ada pencarian tercatat.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <style>
        .kai-page { display: flex; flex-direction: column; gap: 1rem; }
        .kai-hero { padding: 1.1rem 1.25rem; border-radius: 1rem; background: linear-gradient(135deg, rgba(56, 189, 248, .12), rgba(56, 189, 248, .02)); border: 1px solid rgba(56, 189, 248, .25); }
        .kai-hero__eyebrow { text-transform: uppercase; letter-spacing: .08em; font-size: .7rem; font-weight: 700; color: rgb(2 132 199); }
        .kai-hero h1 { margin: .15rem 0 .25rem; font-size: 1.25rem; font-weight: 700; }
        .kai-hero p { margin: 0; font-size: .85rem; color: rgb(100 116 139); max-width: 80ch; }
        .kai-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .75rem; }
        .kai-card { display: flex; flex-direction: column; gap: .15rem; padding: 1rem 1.1rem; border-radius: .9rem; border: 1px solid rgba(148, 163, 184, .3); background: rgba(148, 163, 184, .06); }
        .kai-card__label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: rgb(100 116 139); font-weight: 600; }
        .kai-card__value { font-size: 1.9rem; font-weight: 800; line-height: 1.1; }
        .kai-card__hint { font-size: .8rem; color: rgb(100 116 139); }
        .kai-section { padding: 1rem 1.1rem; border-radius: .9rem; border: 1px solid rgba(148, 163, 184, .25); background: rgba(148, 163, 184, .04); }
        .kai-section__title { margin: 0 0 .2rem; font-size: 1rem; font-weight: 700; }
        .kai-section__desc { margin: 0 0 .8rem; font-size: .82rem; color: rgb(100 116 139); }
        .kai-supplier { padding: .7rem .8rem; border-radius: .7rem; border: 1px solid rgba(148, 163, 184, .22); margin-bottom: .6rem; }
        .kai-supplier__head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .4rem; }
        .kai-flow { display: flex; flex-wrap: wrap; gap: .4rem; list-style: none; margin: 0; padding: 0; counter-reset: kai; }
        .kai-flow li { display: flex; flex-direction: column; gap: .1rem; padding: .4rem .6rem; border-radius: .6rem; background: rgba(56, 189, 248, .1); border: 1px solid rgba(56, 189, 248, .3); counter-increment: kai; position: relative; }
        .kai-flow li::before { content: counter(kai); position: absolute; top: -.5rem; left: -.5rem; width: 1.1rem; height: 1.1rem; border-radius: 50%; background: rgb(2 132 199); color: #fff; font-size: .65rem; font-weight: 700; display: grid; place-items: center; }
        .kai-flow__name { font-size: .85rem; font-weight: 600; }
        .kai-flow__meta { font-size: .68rem; color: rgb(100 116 139); }
        .kai-badge { font-size: .7rem; font-weight: 700; padding: .12rem .5rem; border-radius: 999px; }
        .kai-badge--ok { background: rgba(34, 197, 94, .15); color: rgb(21 128 61); }
        .kai-badge--warn { background: rgba(245, 158, 11, .18); color: rgb(180 83 9); }
        .kai-muted { font-size: .82rem; color: rgb(100 116 139); }
        .kai-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
        .kai-chip { font-size: .8rem; padding: .25rem .6rem; border-radius: 999px; background: rgba(245, 158, 11, .15); border: 1px solid rgba(245, 158, 11, .3); }
        .kai-table-wrap { overflow-x: auto; }
        .kai-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
        .kai-table th, .kai-table td { padding: .45rem .6rem; border-bottom: 1px solid rgba(148, 163, 184, .2); text-align: left; }
        .kai-table th { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: rgb(100 116 139); }
        .kai-right { text-align: right; }
    </style>
</x-filament-panels::page>
