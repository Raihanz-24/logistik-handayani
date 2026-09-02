@php
    $imageUrl = filled($barang?->gambar)
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($barang->gambar)
        : null;
@endphp

<div style="display: grid; gap: 1rem;">
    @if ($imageUrl)
        <div
            style="display: flex; min-height: 16rem; max-height: 70vh; align-items: center; justify-content: center; overflow: hidden; border: 1px solid rgba(148, 163, 184, .28); border-radius: 1rem; background: rgba(15, 23, 42, .04); padding: .75rem;"
        >
            <img
                src="{{ $imageUrl }}"
                alt="Gambar {{ $barang->nama_barang }}"
                loading="eager"
                decoding="async"
                style="display: block; width: 100%; height: auto; max-height: calc(70vh - 1.5rem); object-fit: contain; border-radius: .75rem;"
            >
        </div>

        <div style="text-align: center; line-height: 1.45;">
            <strong style="display: block; font-size: 1rem;">{{ $barang->nama_barang }}</strong>
            <span style="font-size: .875rem; color: rgb(100 116 139);">
                {{ $barang->kode_barang }}@if(filled($barang->satuan)) · {{ $barang->satuan }}@endif
            </span>
        </div>
    @else
        <div style="padding: 3rem 1rem; text-align: center; color: rgb(100 116 139);">
            Gambar barang belum tersedia.
        </div>
    @endif
</div>
