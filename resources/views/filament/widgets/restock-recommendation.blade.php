<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Rekomendasi Prioritas Restock (SAW)
        </x-slot>

        <x-slot name="description">
            Top 5 produk periode {{ $start->translatedFormat('d M Y') }} - {{ $end->translatedFormat('d M Y') }}.
            Frekuensi dan jumlah pemakaian adalah benefit, sedangkan sisa stok adalah cost.
        </x-slot>

        @if ($recommendations->isEmpty())
            <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                Belum ada produk yang dapat dihitung.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-3 text-center">Rank</th>
                            <th class="px-3 py-3">Produk</th>
                            <th class="px-3 py-3 text-right">Frekuensi</th>
                            <th class="px-3 py-3 text-right">Jumlah Pakai</th>
                            <th class="px-3 py-3 text-right">Sisa Stok</th>
                            <th class="px-3 py-3 text-right">Nilai SAW</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($recommendations as $item)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-3 py-3 text-center">
                                    <span @class([
                                        'inline-flex h-7 w-7 items-center justify-center rounded-full font-semibold',
                                        'bg-primary-500 text-white' => $item['peringkat'] === 1,
                                        'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200' => $item['peringkat'] !== 1,
                                    ])>
                                        {{ $item['peringkat'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="font-medium text-gray-950 dark:text-white">
                                        {{ $item['nama_produk'] }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $item['kode_produk'] }}
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    {{ number_format($item['frekuensi_pemakaian']) }} kali
                                    <div class="text-xs text-gray-500">
                                        N: {{ number_format($item['normalisasi_frekuensi'], 3) }}
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    {{ number_format($item['jumlah_pemakaian']) }} {{ $item['satuan'] }}
                                    <div class="text-xs text-gray-500">
                                        N: {{ number_format($item['normalisasi_jumlah'], 3) }}
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    {{ number_format($item['sisa_stok']) }} {{ $item['satuan'] }}
                                    <div class="text-xs text-gray-500">
                                        N: {{ number_format($item['normalisasi_stok'], 3) }}
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <x-filament::badge color="warning">
                                        {{ number_format($item['nilai_preferensi'], 4) }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Bobot:
                frekuensi {{ number_format($weights['frekuensi_pemakaian'] * 100, 2) }}%,
                jumlah pemakaian {{ number_format($weights['jumlah_pemakaian'] * 100, 2) }}%,
                sisa stok {{ number_format($weights['sisa_stok'] * 100, 2) }}%.
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
