<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    @php($ringkasanGudang = $this->ringkasanGudang())

    <div class="stock-page-layout">
        <section class="stock-overview" aria-labelledby="stock-overview-title">
            <div class="stock-overview-heading">
                <div>
                    <h2 id="stock-overview-title">Ringkasan Stok Gudang</h2>
                    <p>Pilih satu atau dua gudang untuk menyaring tabel dengan cepat.</p>
                </div>

                @if (count($gudangAktif))
                    <x-filament::button type="button" color="gray" size="sm" wire:click="resetGudang">
                        Tampilkan Semua
                    </x-filament::button>
                @endif
            </div>

            <div class="stock-summary-grid">
                @foreach ($ringkasanGudang as $key => $ringkasan)
                    @php($aktif = in_array($key, $gudangAktif, true))

                    <article @class(['stock-summary-card', 'is-active' => $aktif])>
                        <div class="stock-summary-card-heading">
                            <div>
                                <span class="stock-summary-label">{{ $ringkasan['label'] }}</span>
                                <strong>{{ number_format($ringkasan['stok'], 0, ',', '.') }}</strong>
                                <small>Total stok · {{ number_format($ringkasan['jumlah_barang'], 0, ',', '.') }} jenis barang</small>
                            </div>

                            <button
                                type="button"
                                wire:click="toggleGudang('{{ $key }}')"
                                @class(['stock-filter-button', 'is-active' => $aktif])
                                aria-pressed="{{ $aktif ? 'true' : 'false' }}"
                            >
                                {{ $aktif ? 'Aktif' : 'Filter' }}
                            </button>
                        </div>

                        <dl class="stock-condition-list">
                            <div><dt>Baik</dt><dd>{{ number_format($ringkasan['baik'], 0, ',', '.') }}</dd></div>
                            <div><dt>Rusak</dt><dd>{{ number_format($ringkasan['rusak'], 0, ',', '.') }}</dd></div>
                            <div><dt>Hilang</dt><dd>{{ number_format($ringkasan['hilang'], 0, ',', '.') }}</dd></div>
                        </dl>
                    </article>
                @endforeach
            </div>

            <p class="stock-filter-hint">
                @if (count($gudangAktif) === 2)
                    Menampilkan stok Gudang Dapur dan Gudang Utama.
                @elseif (count($gudangAktif) === 1)
                    Filter aktif: {{ $ringkasanGudang[$gudangAktif[0]]['label'] }}.
                @else
                    Semua gudang sedang ditampilkan.
                @endif
            </p>
        </section>

        <x-filament-panels::resources.tabs />

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>
</x-filament-panels::page>
