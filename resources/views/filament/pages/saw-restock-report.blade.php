<x-filament-panels::page>
    @php($report = $this->report())
    @php($recommendations = $this->paginatedRecommendations())
    @php($allRecommendations = $report['recommendations'])
    @php($totalUsage = $allRecommendations->sum('jumlah_pemakaian'))
    @php($totalFrequency = $allRecommendations->sum('frekuensi_pemakaian'))

    <div class="sr-page">
        <section class="sr-hero">
            <div>
                <span class="sr-kicker">Sistem pendukung keputusan</span>
                <h1>Analisis Prioritas Restock</h1>
                <p>Lihat seluruh skor SAW dan pola pemakaian barang pada periode yang dipilih. Halaman ini hanya membaca data; stok dan mutasi tidak diubah.</p>
            </div>
            <div class="sr-period">
                <span>Periode aktif</span>
                <strong>{{ $report['start']->translatedFormat('d M Y') }} – {{ $report['end']->translatedFormat('d M Y') }}</strong>
            </div>
        </section>

        <section class="sr-card sr-card--filters">
            <div class="sr-card-heading">
                <div>
                    <span>Filter pemakaian</span>
                    <h2>Pilih periode laporan</h2>
                </div>
                <div class="sr-quick-actions">
                    <x-filament::button color="gray" size="sm" type="button" wire:click="useThisWeek">Minggu ini</x-filament::button>
                    <x-filament::button color="gray" size="sm" type="button" wire:click="useThisMonth">Bulan ini</x-filament::button>
                </div>
            </div>

            <form wire:submit="applyFilters">
                {{ $this->form }}
                <div class="sr-filter-submit">
                    <x-filament::button type="submit" icon="heroicon-m-funnel" wire:loading.attr="disabled" wire:target="applyFilters">
                        Terapkan Filter
                    </x-filament::button>
                </div>
            </form>
        </section>

        <section class="sr-summary-grid">
            <article class="sr-summary-card">
                <span>Barang dianalisis</span>
                <strong>{{ number_format($allRecommendations->count()) }}</strong>
                <small>Seluruh master barang pada periode ini</small>
            </article>
            <article class="sr-summary-card">
                <span>Total pemakaian</span>
                <strong>{{ number_format($totalUsage, 2, ',', '.') }}</strong>
                <small>Akumulasi jumlah mutasi keluar disetujui</small>
            </article>
            <article class="sr-summary-card">
                <span>Frekuensi pemakaian</span>
                <strong>{{ number_format($totalFrequency) }}×</strong>
                <small>Total transaksi pemakaian pada periode ini</small>
            </article>
        </section>

        <section class="sr-card sr-card--results">
            <div class="sr-card-heading">
                <div>
                    <span>Hasil perhitungan</span>
                    <h2>Seluruh peringkat prioritas</h2>
                </div>
                <span class="sr-count">Menampilkan {{ $recommendations->firstItem() ?? 0 }}–{{ $recommendations->lastItem() ?? 0 }} dari {{ $recommendations->total() }} barang</span>
            </div>

            @if ($recommendations->isEmpty())
                <div class="sr-empty">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" />
                    <strong>Barang tidak ditemukan</strong>
                    <span>Ubah kata pencarian atau rentang tanggal Anda.</span>
                </div>
            @else
                <div class="sr-result-list">
                    @foreach ($recommendations as $item)
                        <article class="sr-result-row">
                            <span class="sr-rank">{{ $item['peringkat'] }}</span>
                            <div class="sr-item">
                                <strong>{{ $item['nama_barang'] }}</strong>
                                <span>{{ $item['kode_barang'] }}</span>
                            </div>
                            <div class="sr-metrics">
                                <div><span>Frekuensi</span><strong>{{ number_format($item['frekuensi_pemakaian']) }}×</strong></div>
                                <div><span>Pemakaian</span><strong>{{ number_format($item['jumlah_pemakaian'], 2, ',', '.') }} {{ $item['satuan'] }}</strong></div>
                                <div><span>Sisa stok</span><strong>{{ number_format($item['sisa_stok'], 2, ',', '.') }} {{ $item['satuan'] }}</strong></div>
                            </div>
                            <div class="sr-score">
                                <span>Skor SAW</span>
                                <strong>{{ number_format($item['nilai_preferensi'], 4) }}</strong>
                                <i><b style="--score: {{ min(100, max(0, $item['nilai_preferensi'] * 100)) }}%"></b></i>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="sr-pagination">{{ $recommendations->onEachSide(1)->links() }}</div>
            @endif

            <footer class="sr-weights">
                <span>Frekuensi {{ number_format($report['weights']['frekuensi_pemakaian'] * 100, 1) }}%</span>
                <span>Jumlah pemakaian {{ number_format($report['weights']['jumlah_pemakaian'] * 100, 1) }}%</span>
                <span>Sisa stok {{ number_format($report['weights']['sisa_stok'] * 100, 1) }}%</span>
            </footer>
        </section>
    </div>

    <style>
        .sr-page{display:grid;gap:1rem}.sr-hero,.sr-card,.sr-summary-card{border:1px solid var(--wd-border);border-radius:1.1rem;background:var(--wd-surface);box-shadow:var(--wd-shadow)}.sr-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.35rem;background:radial-gradient(circle at 100% 0,rgba(245,179,1,.18),transparent 35%),linear-gradient(135deg,var(--wd-surface),var(--wd-surface-soft))}.sr-kicker,.sr-card-heading>div>span{color:var(--wd-amber-light);font-size:.65rem;font-weight:850;letter-spacing:.08em;text-transform:uppercase}.sr-hero h1,.sr-card h2{margin:.25rem 0;color:var(--wd-text);font-weight:850}.sr-hero h1{font-size:1.45rem}.sr-hero p{max-width:43rem;margin:0;color:var(--wd-muted);font-size:.75rem;line-height:1.6}.sr-period{display:grid;gap:.25rem;min-width:13rem;padding:.8rem 1rem;border:1px solid var(--wd-feature-border);border-radius:.85rem;background:var(--wd-feature-inset)}.sr-period span,.sr-summary-card span{color:var(--wd-muted);font-size:.62rem}.sr-period strong{color:var(--wd-text);font-size:.75rem}.sr-card{padding:1rem}.sr-card-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.8rem}.sr-card h2{font-size:1rem}.sr-quick-actions{display:flex;gap:.45rem}.sr-filter-submit{display:flex;justify-content:flex-end;margin-top:.85rem}.sr-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}.sr-summary-card{display:grid;gap:.2rem;padding:1rem;background:linear-gradient(145deg,var(--wd-surface),var(--wd-feature-inset))}.sr-summary-card strong{color:var(--wd-text);font-size:1.35rem;line-height:1.25}.sr-summary-card small{color:var(--wd-muted-soft);font-size:.61rem;line-height:1.45}.sr-card--results{padding:0}.sr-card--results>.sr-card-heading{margin:0;padding:1rem;border-bottom:1px solid var(--wd-border)}.sr-count{padding:.3rem .5rem;border-radius:999px;color:var(--wd-amber-light);background:var(--wd-feature-inset);font-size:.6rem;font-weight:800}.sr-result-list{display:grid}.sr-result-row{display:grid;grid-template-columns:auto minmax(10rem,1.1fr) minmax(15rem,1.35fr) minmax(7.5rem,.7fr);align-items:center;gap:.9rem;padding:.85rem 1rem;border-bottom:1px solid var(--wd-row-border)}.sr-rank{display:grid;place-items:center;width:1.8rem;height:1.8rem;border-radius:.55rem;color:var(--wd-amber-light);background:var(--wd-feature-inset);font-size:.7rem;font-weight:900}.sr-item{display:grid;gap:.1rem;min-width:0}.sr-item strong{overflow:hidden;color:var(--wd-text);font-size:.72rem;text-overflow:ellipsis;white-space:nowrap}.sr-item span{color:var(--wd-muted);font-size:.59rem}.sr-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:.35rem}.sr-metrics div{display:grid;gap:.1rem;padding:.38rem .45rem;border-radius:.5rem;background:var(--wd-panel-inset)}.sr-metrics span,.sr-score span{color:var(--wd-muted);font-size:.55rem}.sr-metrics strong{overflow:hidden;color:var(--wd-text);font-size:.63rem;text-overflow:ellipsis;white-space:nowrap}.sr-score{display:grid;gap:.16rem}.sr-score strong{color:var(--wd-amber-light);font-size:.8rem}.sr-score i{display:block;overflow:hidden;height:.25rem;border-radius:999px;background:var(--wd-panel-inset)}.sr-score b{display:block;width:var(--score);height:100%;border-radius:inherit;background:linear-gradient(90deg,#f59e0b,#facc15)}.sr-pagination{padding:.8rem 1rem}.sr-weights{display:flex;flex-wrap:wrap;gap:.45rem;padding:.8rem 1rem;border-top:1px solid var(--wd-border);color:var(--wd-muted);font-size:.6rem}.sr-weights span{padding:.25rem .4rem;border-radius:.4rem;background:var(--wd-panel-inset)}.sr-empty{display:grid;place-items:center;gap:.35rem;padding:3rem 1rem;color:var(--wd-muted);text-align:center}.sr-empty svg{width:2rem;color:var(--wd-amber-light)}.sr-empty strong{color:var(--wd-text);font-size:.85rem}.sr-empty span{font-size:.66rem}@media(max-width:900px){.sr-result-row{grid-template-columns:auto minmax(0,1fr);gap:.65rem}.sr-metrics,.sr-score{grid-column:1/-1}.sr-score{grid-template-columns:auto auto 1fr;align-items:center}.sr-score i{min-width:4rem}.sr-summary-grid{grid-template-columns:1fr 1fr}.sr-summary-card:last-child{grid-column:1/-1}}@media(max-width:600px){.sr-hero{align-items:stretch;flex-direction:column}.sr-period{min-width:0}.sr-card-heading{align-items:stretch;flex-direction:column}.sr-quick-actions{display:grid;grid-template-columns:1fr 1fr}.sr-quick-actions .fi-btn,.sr-filter-submit .fi-btn{width:100%;justify-content:center}.sr-filter-submit{display:block}.sr-summary-grid{grid-template-columns:1fr}.sr-summary-card:last-child{grid-column:auto}.sr-count{align-self:flex-start}.sr-metrics{grid-template-columns:1fr 1fr}.sr-metrics div:last-child{grid-column:1/-1}.sr-item strong{white-space:normal}.sr-weights{display:grid;grid-template-columns:1fr}.sr-weights span{text-align:center}}
    </style>
</x-filament-panels::page>
