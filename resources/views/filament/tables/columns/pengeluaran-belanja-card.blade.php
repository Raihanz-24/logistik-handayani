@php
    /** @var \App\Models\PengeluaranBelanja $transaction */
    $transaction = $getRecord();
    $items = $transaction->items;
    $folders = $transaction->fotoBarangSessions;
    $receipts = $transaction->notas;
    $legacyReceipt = filled($transaction->foto_nota);
@endphp

<article class="wm-kb-store-card">
    <header class="wm-kb-store-card__header">
        <span class="wm-kb-store-card__icon" aria-hidden="true">
            <x-heroicon-o-building-storefront />
        </span>
        <span class="wm-kb-store-card__title">
            <strong>{{ $transaction->namaSupplier() }}</strong>
            <small>{{ $items->count() }} barang · {{ $transaction->jumlahNota() }} foto nota</small>
        </span>
        <strong class="wm-kb-store-card__total">
            {{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah((int) $transaction->nominal) }}
        </strong>
    </header>

    @if ($transaction->keterangan)
        <p class="wm-kb-store-card__note">{{ $transaction->keterangan }}</p>
    @endif

    <div class="wm-kb-store-card__items">
        @forelse ($items as $item)
            @php
                $quantity = rtrim(rtrim(number_format((float) $item->jumlah, 3, ',', '.'), '0'), ',');
                $photoLinks = $item->photoLinks
                    ->filter(fn ($link): bool => $link->photo?->session !== null)
                    ->values();
                $firstPhotoLink = $photoLinks->first();
                $firstPhotoUrl = $firstPhotoLink
                    ? \App\Filament\Pages\FotoBarangFolder::photoUrl($firstPhotoLink->photo->session, $firstPhotoLink->photo)
                    : null;
            @endphp
            <div class="wm-kb-store-item">
                <span>
                    @if ($firstPhotoUrl)
                        <a class="wm-kb-store-item__photo-link" href="{{ $firstPhotoUrl }}" wire:navigate>
                            <strong>{{ $item->namaBarang() }}</strong>
                            <x-heroicon-m-camera />
                        </a>
                    @else
                        <strong>{{ $item->namaBarang() }}</strong>
                    @endif
                    <small>{{ $item->kode_barang_snapshot }} · {{ $quantity }} {{ $item->satuan_snapshot ?: '' }} × {{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah((int) $item->harga_satuan) }}</small>
                    @if ($item->keterangan)
                        <em>{{ $item->keterangan }}</em>
                    @endif
                    @if ($photoLinks->isNotEmpty())
                        <nav class="wm-kb-store-item__photos" aria-label="Foto barang {{ $item->namaBarang() }}">
                            @foreach ($photoLinks as $photoLink)
                                <a
                                    href="{{ \App\Filament\Pages\FotoBarangFolder::photoUrl($photoLink->photo->session, $photoLink->photo) }}"
                                    wire:navigate
                                >
                                    <x-heroicon-m-photo /> Foto #{{ str_pad((string) $photoLink->photo->urutan, 2, '0', STR_PAD_LEFT) }}
                                </a>
                            @endforeach
                        </nav>
                    @endif
                </span>
                <b>{{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah((int) $item->subtotal) }}</b>
            </div>
        @empty
            <div class="wm-kb-store-card__legacy">
                Transaksi lama · detail barang belum dicatat. Nominal lama tetap tersimpan.
            </div>
        @endforelse
    </div>

    @if ($receipts->isNotEmpty() || $legacyReceipt)
        <div class="wm-kb-store-card__receipts" aria-label="Foto nota">
            @if ($legacyReceipt)
                <a href="{{ route('media.show', ['path' => $transaction->foto_nota]) }}" target="_blank" rel="noopener">
                    <img src="{{ route('media.show', ['path' => $transaction->foto_nota]) }}" alt="Nota lama {{ $transaction->namaSupplier() }}" loading="lazy">
                </a>
            @endif
            @foreach ($receipts as $nota)
                <a href="{{ route('media.show', ['path' => $nota->path]) }}" target="_blank" rel="noopener">
                    <img src="{{ route('media.show', ['path' => $nota->path]) }}" alt="Nota {{ $loop->iteration }} {{ $transaction->namaSupplier() }}" loading="lazy">
                </a>
            @endforeach
        </div>
    @endif

    <footer class="wm-kb-store-card__footer">
        <div class="wm-kb-store-card__folder-status">
            @if ($folders->isNotEmpty())
                <span class="wm-kb-store-card__folders">
                    <x-heroicon-m-camera />
                    @foreach ($folders as $folder)
                        <a href="{{ \App\Filament\Pages\FotoBarangFolder::getUrl(['session' => $folder->uuid]) }}" wire:navigate>
                            {{ $folder->code() }}
                        </a>
                    @endforeach
                </span>
            @else
                <span class="wm-kb-store-card__unlinked">Belum terhubung ke Foto Maps</span>
            @endif
        </div>

        <div class="wm-kb-store-card__photo-actions">
            <button
                type="button"
                wire:click.stop="mountTableAction('atur-foto-maps', '{{ $transaction->getKey() }}')"
                wire:loading.attr="disabled"
                wire:target="mountTableAction"
            >
                <x-heroicon-m-link />
                <span>Hubungkan Folder</span>
            </button>

            <a
                href="{{ \App\Filament\Pages\FotoBarangMaps::getUrl(['pengeluaran' => $transaction->getKey()]) }}"
                wire:navigate
            >
                <x-heroicon-m-camera />
                <span>Buat Sesi Foto Baru</span>
            </a>
        </div>
    </footer>
</article>
