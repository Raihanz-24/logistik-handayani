<x-filament-widgets::widget>
    <section class="wd-panel wd-saw">
        <header class="wd-panel__header">
            <div>
                <span class="wd-kicker">Sistem pendukung keputusan</span>
                <h2>Prioritas restock metode SAW</h2>
                <p>
                    Top {{ $recommendations->count() }} rekomendasi periode {{ $start->translatedFormat('d M Y') }}
                    - {{ $end->translatedFormat('d M Y') }}.
                </p>
            </div>
            <span class="wd-method-badge">
                <x-filament::icon icon="heroicon-m-calculator" />
                Simple Additive Weighting
            </span>
        </header>

        @if ($recommendations->isEmpty())
            <div class="wd-empty-chart">
                <x-filament::icon icon="heroicon-o-chart-bar-square" />
                <strong>Belum ada rekomendasi</strong>
                <span>Tambahkan histori mutasi keluar pada periode yang dipilih.</span>
            </div>
        @else
            @php($winner = $recommendations->first())

            <div class="wd-saw-layout">
                <article class="wd-winner">
                    <div class="wd-winner__glow"></div>
                    <div class="wd-winner__rank">#1 Prioritas utama</div>
                    <span class="wd-winner__code">{{ $winner['kode_barang'] }}</span>
                    <h3>{{ $winner['nama_barang'] }}</h3>
                    <p>Barang dengan nilai preferensi tertinggi untuk segera dilakukan restock.</p>

                    <div class="wd-winner__score">
                        <span>Nilai preferensi</span>
                        <strong>{{ number_format($winner['nilai_preferensi'], 4) }}</strong>
                    </div>

                    <div class="wd-winner__metrics">
                        <div>
                            <span>Frekuensi</span>
                            <strong>{{ number_format($winner['frekuensi_pemakaian']) }}x</strong>
                        </div>
                        <div>
                            <span>Pemakaian</span>
                            <strong>{{ number_format($winner['jumlah_pemakaian']) }} {{ $winner['satuan'] }}</strong>
                        </div>
                        <div>
                            <span>Sisa stok</span>
                            <strong>{{ number_format($winner['sisa_stok']) }} {{ $winner['satuan'] }}</strong>
                        </div>
                    </div>

                    <a href="{{ $winner['url'] }}" class="wd-winner__action">
                        Lihat detail barang
                        <x-filament::icon icon="heroicon-m-arrow-right" />
                    </a>
                </article>

                <div class="wd-ranking">
                    <div class="wd-ranking__heading">
                        <div>
                            <strong>Peringkat rekomendasi</strong>
                            <span>Semakin tinggi skor, semakin mendesak prioritas restock.</span>
                        </div>
                        <span>Skor SAW</span>
                    </div>

                    @foreach ($recommendations as $item)
                        <a href="{{ $item['url'] }}" class="wd-rank-row">
                            <span class="wd-rank-row__number wd-rank-row__number--{{ $item['peringkat'] }}">
                                {{ $item['peringkat'] }}
                            </span>

                            <div class="wd-rank-row__barang">
                                <strong>{{ $item['nama_barang'] }}</strong>
                                <span>{{ $item['kode_barang'] }}</span>
                            </div>

                            <div class="wd-rank-row__metrics">
                                <div>
                                    <span>Frekuensi</span>
                                    <strong>{{ number_format($item['frekuensi_pemakaian']) }}x</strong>
                                </div>
                                <div>
                                    <span>Pemakaian</span>
                                    <strong>{{ number_format($item['jumlah_pemakaian']) }} {{ $item['satuan'] }}</strong>
                                </div>
                                <div>
                                    <span>Sisa stok</span>
                                    <strong>{{ number_format($item['sisa_stok']) }} {{ $item['satuan'] }}</strong>
                                </div>
                            </div>

                            <div class="wd-rank-row__score">
                                <span>Skor</span>
                                <strong>{{ number_format($item['nilai_preferensi'], 4) }}</strong>
                                <i><b style="--score-width: {{ $item['score_percentage'] }}%"></b></i>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>

            <footer class="wd-saw-footer">
                <span><i class="wd-dot wd-dot--amber"></i> Frekuensi benefit {{ number_format($weights['frekuensi_pemakaian'] * 100, 1) }}%</span>
                <span><i class="wd-dot wd-dot--green"></i> Jumlah pemakaian benefit {{ number_format($weights['jumlah_pemakaian'] * 100, 1) }}%</span>
                <span><i class="wd-dot wd-dot--blue"></i> Sisa stok cost {{ number_format($weights['sisa_stok'] * 100, 1) }}%</span>
            </footer>
        @endif
    </section>
</x-filament-widgets::widget>
