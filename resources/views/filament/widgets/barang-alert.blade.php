<x-filament-widgets::widget>
    <section class="wd-panel wd-stock-chart">
        <header class="wd-panel__header">
            <div>
                <span class="wd-kicker">Peringatan stok</span>
                <h2>Stok hampir habis</h2>
                <p>Visualisasi barang dengan stok kurang dari 10 unit di seluruh gudang.</p>
            </div>
            <div class="wd-panel__badge">
                <span class="wd-pulse"></span>
                {{ $criticalCount }} item kritis
            </div>
        </header>

        @if ($rows->isEmpty())
            <div class="wd-empty-chart">
                <x-filament::icon icon="heroicon-o-check-circle" />
                <strong>Semua stok dalam kondisi aman</strong>
                <span>Tidak ada barang dengan stok di bawah ambang 10 unit.</span>
            </div>
        @else
            <div class="wd-chart">
                <div class="wd-chart__scale">
                    <span>0</span>
                    <span>2</span>
                    <span>4</span>
                    <span>6</span>
                    <span>8</span>
                    <span>10</span>
                </div>

                @foreach ($rows as $row)
                    @php
                        $barWidth = max(2, min(100, ($row['stock'] / $maxStock) * 100));
                    @endphp
                    <div class="wd-chart-row">
                        <div class="wd-chart-row__label">
                            <strong>{{ $row['name'] }}</strong>
                            <span>{{ $row['code'] }} · {{ $row['location'] }}</span>
                        </div>
                        <div class="wd-chart-row__plot">
                            <div class="wd-chart-row__grid"></div>
                            <div
                                class="wd-chart-row__bar wd-chart-row__bar--{{ $row['tone'] }}"
                                style="--bar-width: {{ $barWidth }}%"
                            ></div>
                        </div>
                        <div class="wd-chart-row__value">
                            <strong>{{ number_format($row['stock']) }}</strong>
                            <span>{{ $row['unit'] }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</x-filament-widgets::widget>
