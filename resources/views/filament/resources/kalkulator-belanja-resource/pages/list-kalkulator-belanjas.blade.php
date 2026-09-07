<x-filament-panels::page>
    <div class="wm-kb-ledger">
        <section class="wm-kb-filter-card" aria-labelledby="wm-kb-filter-title">
            <div class="wm-kb-filter-card__heading">
                <span class="wm-kb-filter-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h18M6.75 9.75h10.5M10 15h4M12 19.5v-9.75" />
                    </svg>
                </span>
                <span>
                    <strong id="wm-kb-filter-title">Filter Riwayat</strong>
                    <small>Pilih bulan atau rentang tanggal tertentu</small>
                </span>

                @if ($hasActiveFilters)
                    <button type="button" wire:click="resetLedgerFilters" class="wm-kb-filter-card__reset">
                        Reset
                    </button>
                @endif
            </div>

            <div class="wm-kb-filter-fields">
                <label class="wm-kb-filter-field wm-kb-filter-field--search">
                    <span>Cari sesi atau toko</span>
                    <span class="wm-kb-filter-field__control">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="11" cy="11" r="7" />
                            <path stroke-linecap="round" d="m20 20-3.7-3.7" />
                        </svg>
                        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Contoh: Arista Mart" autocomplete="off">
                    </span>
                </label>

                <label class="wm-kb-filter-field">
                    <span>Bulan</span>
                    <select wire:model.live="filterMonth">
                        <option value="">Semua bulan</option>
                        @foreach ($monthOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="wm-kb-filter-field">
                    <span>Dari tanggal</span>
                    <input type="date" wire:model.live="dateFrom">
                </label>

                <label class="wm-kb-filter-field">
                    <span>Sampai tanggal</span>
                    <input type="date" wire:model.live="dateTo">
                </label>
            </div>
        </section>

        <section class="wm-kb-summary-grid" aria-label="Ringkasan belanja {{ $summary['period'] }}">
            <article class="wm-kb-summary-card wm-kb-summary-card--out">
                <span class="wm-kb-summary-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h10v10M17 7 7 17" />
                    </svg>
                </span>
                <span>
                    <small>Total uang keluar</small>
                    <strong>{{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah($summary['total_out']) }}</strong>
                    <em>{{ $summary['period'] }}</em>
                </span>
            </article>

            <article class="wm-kb-summary-card wm-kb-summary-card--transactions">
                <span class="wm-kb-summary-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6m-6 4h6" />
                    </svg>
                </span>
                <span>
                    <small>Total transaksi</small>
                    <strong>{{ number_format($summary['transactions'], 0, ',', '.') }}</strong>
                    <em>{{ $summary['receipts'] }} transaksi memiliki nota</em>
                </span>
            </article>

            <article class="wm-kb-summary-card wm-kb-summary-card--sessions">
                <span class="wm-kb-summary-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3m8-3v3M3.5 9h17M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" />
                    </svg>
                </span>
                <span>
                    <small>Sesi belanja</small>
                    <strong>{{ number_format($summary['sessions'], 0, ',', '.') }}</strong>
                    <em>Satu toko dicatat sebagai satu transaksi</em>
                </span>
            </article>
        </section>

        <div class="wm-kb-history-heading">
            <span>
                <strong>Riwayat Belanja</strong>
                <small>Urutan terbaru berdasarkan tanggal belanja</small>
            </span>
            <span>{{ $records->total() }} sesi</span>
        </div>

        @if ($records->isNotEmpty())
            <section class="wm-kb-history-grid" aria-label="Daftar riwayat belanja">
                @foreach ($records as $record)
                    @include('filament.tables.columns.kalkulator-belanja-mobile', [
                        'record' => $record,
                        'standalone' => true,
                    ])
                @endforeach
            </section>

            @if ($records->hasPages())
                <div class="wm-kb-pagination">
                    {{ $records->onEachSide(1)->links() }}
                </div>
            @endif
        @else
            <section class="wm-kb-empty-state">
                <span aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6m-6 4h6" />
                    </svg>
                </span>
                <strong>Riwayat tidak ditemukan</strong>
                <p>Ubah filter atau buat sesi belanja baru.</p>
            </section>
        @endif
    </div>
</x-filament-panels::page>
