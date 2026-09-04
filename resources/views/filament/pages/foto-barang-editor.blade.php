<x-filament-panels::page>
    @php
        $selectedSession = $this->selectedSession();
        $selectedPhoto = $this->selectedPhoto();
        $sessions = $selectedSession ? null : $this->sessions();
        $originalPhotos = $selectedSession ? $this->originalPhotos() : null;
        $editedPhotos = $selectedSession ? $this->editedPhotos() : null;
    @endphp

    <div class="fme-page">
        <section class="fme-hero">
            <div>
                <span>Editor non-destruktif</span>
                <h1>Ubah tanggal dan jam Foto Maps</h1>
                <p>Pilih foto server, tentukan waktu baru, lalu unduh salinannya. Foto asli selalu dipertahankan.</p>
            </div>
            <x-filament::icon icon="heroicon-o-photo" />
        </section>

        @if (! $selectedSession)
            <section class="fme-panel">
                <div class="fme-heading">
                    <div>
                        <span>Langkah 1</span>
                        <h2>Pilih folder foto</h2>
                    </div>
                    <div class="fme-filters">
                        <input type="date" wire:model.live="historyDate" max="{{ now('Asia/Jakarta')->toDateString() }}">
                        <button type="button" wire:click="showTodaySessions">Hari ini</button>
                        <button type="button" wire:click="showAllSessions">Semua folder</button>
                    </div>
                </div>

                <div class="fme-folder-grid">
                    @forelse ($sessions as $session)
                        <button type="button" class="fme-folder" wire:click="selectSession({{ $session->id }})">
                            <span class="fme-folder__icon"><x-filament::icon icon="heroicon-o-folder" /></span>
                            <span>
                                <strong>{{ $session->judul }}</strong>
                                <small>{{ $session->code() }} · {{ $session->dimulai_at?->locale('id')->translatedFormat('d M Y, H:i') }} WIB</small>
                                <b>{{ $session->completed_items_count }} foto siap diedit</b>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" />
                        </button>
                    @empty
                        <div class="fme-empty">
                            <x-filament::icon icon="heroicon-o-folder-open" />
                            <strong>Belum ada foto siap diedit</strong>
                            <span>Coba pilih tanggal lain atau tampilkan semua folder.</span>
                        </div>
                    @endforelse
                </div>

                @if ($sessions->hasPages())
                    <div class="fme-pagination">{{ $sessions->links() }}</div>
                @endif
            </section>
        @else
            <section class="fme-folder-bar">
                <button type="button" wire:click="closeSession">
                    <x-filament::icon icon="heroicon-m-arrow-left" /> Kembali ke folder
                </button>
                <div>
                    <span>Folder aktif</span>
                    <strong>{{ $selectedSession->judul }}</strong>
                    <small>{{ $selectedSession->code() }}</small>
                </div>
            </section>

            <div class="fme-workspace">
                <section class="fme-panel">
                    <div class="fme-heading">
                        <div>
                            <span>Langkah 2</span>
                            <h2>Pilih foto asli</h2>
                        </div>
                    </div>

                    <div class="fme-photo-grid">
                        @forelse ($originalPhotos as $photo)
                            <button
                                type="button"
                                class="fme-photo {{ $selectedPhoto?->id === $photo->id ? 'is-selected' : '' }}"
                                wire:click="selectPhoto({{ $photo->id }})"
                            >
                                <img
                                    src="{{ route('foto-barang.thumbnail', [$selectedSession, $photo]) }}?v={{ $photo->updated_at->getTimestamp() }}"
                                    alt="Foto {{ $photo->urutan }}"
                                    loading="lazy"
                                >
                                <span><b>#{{ str_pad((string) $photo->urutan, 2, '0', STR_PAD_LEFT) }}</b>{{ $photo->diambil_at?->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }}</span>
                            </button>
                        @empty
                            <div class="fme-empty"><strong>Tidak ada foto yang siap diedit.</strong></div>
                        @endforelse
                    </div>

                    @if ($originalPhotos->hasPages())
                        <div class="fme-pagination">{{ $originalPhotos->links() }}</div>
                    @endif
                </section>

                <aside class="fme-editor-card">
                    <div class="fme-heading">
                        <div>
                            <span>Langkah 3</span>
                            <h2>Atur waktu baru</h2>
                        </div>
                    </div>

                    @if ($selectedPhoto)
                        <img
                            class="fme-preview"
                            src="{{ route('foto-barang.preview', [$selectedSession, $selectedPhoto]) }}?v={{ $selectedPhoto->updated_at->getTimestamp() }}"
                            alt="Preview foto yang dipilih"
                        >
                        <div class="fme-original-time">
                            <span>Waktu asli</span>
                            <strong>{{ $selectedPhoto->diambil_at?->setTimezone('Asia/Jakarta')->locale('id')->translatedFormat('d F Y, H:i') }} WIB</strong>
                        </div>
                        <label>
                            <span>Tanggal baru</span>
                            <input type="date" wire:model="editDate" max="{{ now('Asia/Jakarta')->toDateString() }}">
                            @error('editDate') <small>{{ $message }}</small> @enderror
                        </label>
                        <label>
                            <span>Jam baru</span>
                            <input type="time" wire:model="editTime">
                            @error('editTime') <small>{{ $message }}</small> @enderror
                        </label>
                        <button type="button" class="fme-submit" wire:click="createEditedPhoto" wire:loading.attr="disabled">
                            <x-filament::icon icon="heroicon-o-sparkles" />
                            <span wire:loading.remove wire:target="createEditedPhoto">Buat Hasil Edit</span>
                            <span wire:loading wire:target="createEditedPhoto">Memproses foto...</span>
                        </button>
                        <p class="fme-safe"><x-filament::icon icon="heroicon-o-shield-check" /> File asli tidak akan ditimpa.</p>
                    @else
                        <div class="fme-empty fme-empty--compact">
                            <x-filament::icon icon="heroicon-o-cursor-arrow-rays" />
                            <strong>Pilih salah satu foto</strong>
                            <span>Form waktu akan muncul setelah foto dipilih.</span>
                        </div>
                    @endif
                </aside>
            </div>

            <section class="fme-panel fme-results">
                <div class="fme-heading">
                    <div>
                        <span>Folder terpisah</span>
                        <h2>Hasil Edit</h2>
                    </div>
                    <p>Semua salinan hasil perubahan waktu tersimpan di sini.</p>
                </div>

                <div class="fme-result-grid">
                    @forelse ($editedPhotos as $edit)
                        <article>
                            <a href="{{ route('foto-barang.edit-preview', [$selectedSession, $edit]) }}" target="_blank" rel="noopener">
                                <img src="{{ route('foto-barang.edit-preview', [$selectedSession, $edit]) }}?v={{ $edit->updated_at->getTimestamp() }}" alt="Hasil foto {{ $edit->photo?->urutan }}" loading="lazy">
                            </a>
                            <div>
                                <span>Foto #{{ str_pad((string) ($edit->photo?->urutan ?? 0), 2, '0', STR_PAD_LEFT) }}</span>
                                <strong>{{ $edit->waktu_baru?->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</strong>
                                <a href="{{ route('foto-barang.edit-download', [$selectedSession, $edit]) }}">
                                    <x-filament::icon icon="heroicon-m-arrow-down-tray" /> Unduh
                                </a>
                            </div>
                        </article>
                    @empty
                        <div class="fme-empty fme-empty--compact">
                            <x-filament::icon icon="heroicon-o-photo" />
                            <strong>Folder hasil masih kosong</strong>
                            <span>Pilih foto dan buat salinan dengan waktu baru.</span>
                        </div>
                    @endforelse
                </div>

                @if ($editedPhotos->hasPages())
                    <div class="fme-pagination">{{ $editedPhotos->links() }}</div>
                @endif
            </section>
        @endif
    </div>

    <style>
        .fme-page { --fme-bg:#fff; --fme-soft:#f8fafc; --fme-line:#e2e8f0; --fme-ink:#172033; --fme-muted:#64748b; display:grid; gap:1rem; color:var(--fme-ink); }
        .dark .fme-page { --fme-bg:#111827; --fme-soft:#172033; --fme-line:#334155; --fme-ink:#f8fafc; --fme-muted:#94a3b8; }
        .fme-hero { display:flex; align-items:center; justify-content:space-between; gap:1rem; overflow:hidden; padding:1.35rem 1.5rem; border-radius:1.2rem; color:#fff; background:linear-gradient(135deg,#7f1d1d,#be123c 52%,#f97316); box-shadow:0 18px 45px rgba(159,18,57,.18); }
        .fme-hero span,.fme-heading span,.fme-folder-bar span { font-size:.68rem; font-weight:900; letter-spacing:.12em; text-transform:uppercase; opacity:.78; }
        .fme-hero h1 { margin:.22rem 0; font-size:clamp(1.25rem,3vw,2rem); font-weight:900; }
        .fme-hero p { max-width:42rem; font-size:.85rem; opacity:.9; }
        .fme-hero>svg { flex:0 0 auto; width:4.25rem; opacity:.22; }
        .fme-panel,.fme-editor-card,.fme-folder-bar { padding:1rem; border:1px solid var(--fme-line); border-radius:1rem; background:var(--fme-bg); box-shadow:0 10px 30px rgba(15,23,42,.05); }
        .fme-heading { display:flex; align-items:flex-end; justify-content:space-between; gap:.75rem; margin-bottom:1rem; }
        .fme-heading h2 { margin-top:.12rem; font-size:1rem; font-weight:900; }
        .fme-heading p { color:var(--fme-muted); font-size:.73rem; }
        .fme-filters { display:flex; flex-wrap:wrap; gap:.4rem; }
        .fme-filters input,.fme-filters button,.fme-editor-card input { min-height:2.35rem; padding:.45rem .65rem; border:1px solid var(--fme-line); border-radius:.65rem; color:var(--fme-ink); background:var(--fme-soft); font-size:.75rem; }
        .fme-filters button { font-weight:800; cursor:pointer; }
        .fme-folder-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.7rem; }
        .fme-folder { display:grid; grid-template-columns:auto minmax(0,1fr) auto; align-items:center; gap:.7rem; padding:.85rem; border:1px solid var(--fme-line); border-radius:.85rem; color:var(--fme-ink); background:var(--fme-soft); text-align:left; transition:.15s; }
        .fme-folder:hover { border-color:#fb7185; transform:translateY(-1px); box-shadow:0 8px 22px rgba(159,18,57,.09); }
        .fme-folder__icon { display:grid; place-items:center; width:2.7rem; height:2.7rem; border-radius:.75rem; color:#be123c; background:#ffe4e6; }
        .dark .fme-folder__icon { color:#fda4af; background:#4c1d2b; }
        .fme-folder__icon svg,.fme-folder>svg { width:1.3rem; }
        .fme-folder>span:nth-child(2) { display:grid; min-width:0; gap:.15rem; }
        .fme-folder strong { overflow:hidden; font-size:.82rem; text-overflow:ellipsis; white-space:nowrap; }
        .fme-folder small { color:var(--fme-muted); font-size:.66rem; }
        .fme-folder b { color:#be123c; font-size:.67rem; }
        .fme-folder-bar { display:flex; align-items:center; gap:1rem; }
        .fme-folder-bar>button { display:flex; align-items:center; gap:.35rem; padding:.55rem .7rem; border-radius:.65rem; color:#be123c; background:#fff1f2; font-size:.73rem; font-weight:850; }
        .fme-folder-bar>button svg { width:1rem; }
        .fme-folder-bar>div { display:grid; gap:.05rem; }
        .fme-folder-bar strong { font-size:.9rem; }
        .fme-folder-bar small { color:var(--fme-muted); font-size:.66rem; }
        .fme-workspace { display:grid; grid-template-columns:minmax(0,1.45fr) minmax(17rem,.55fr); gap:1rem; align-items:start; }
        .fme-photo-grid,.fme-result-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.65rem; }
        .fme-photo { overflow:hidden; padding:.25rem; border:2px solid transparent; border-radius:.8rem; color:var(--fme-ink); background:var(--fme-soft); text-align:left; }
        .fme-photo.is-selected { border-color:#e11d48; box-shadow:0 0 0 3px rgba(225,29,72,.12); }
        .fme-photo img,.fme-result-grid img { display:block; width:100%; aspect-ratio:3/4; border-radius:.6rem; object-fit:cover; background:#e2e8f0; }
        .fme-photo span { display:flex; justify-content:space-between; gap:.3rem; padding:.45rem .25rem .2rem; color:var(--fme-muted); font-size:.63rem; }
        .fme-photo span b { color:#be123c; }
        .fme-editor-card { position:sticky; top:5.5rem; }
        .fme-preview { display:block; width:100%; max-height:25rem; border-radius:.8rem; object-fit:contain; background:#0f172a; }
        .fme-original-time { display:grid; gap:.1rem; margin:.7rem 0; padding:.65rem; border-radius:.7rem; background:var(--fme-soft); }
        .fme-original-time span { color:var(--fme-muted); font-size:.65rem; }
        .fme-original-time strong { font-size:.75rem; }
        .fme-editor-card label { display:grid; gap:.3rem; margin-top:.65rem; font-size:.72rem; font-weight:800; }
        .fme-editor-card label small { color:#dc2626; font-size:.65rem; }
        .fme-submit { display:flex; align-items:center; justify-content:center; gap:.4rem; width:100%; margin-top:.85rem; padding:.7rem; border-radius:.75rem; color:#fff; background:linear-gradient(135deg,#be123c,#f97316); font-size:.76rem; font-weight:900; box-shadow:0 8px 20px rgba(190,18,60,.2); }
        .fme-submit:disabled { opacity:.6; cursor:wait; }
        .fme-submit svg,.fme-safe svg { width:1rem; }
        .fme-safe { display:flex; justify-content:center; gap:.3rem; margin-top:.55rem; color:#15803d; font-size:.65rem; font-weight:750; }
        .fme-results { margin-top:.1rem; }
        .fme-result-grid article { overflow:hidden; border:1px solid var(--fme-line); border-radius:.8rem; background:var(--fme-soft); }
        .fme-result-grid article>div { display:grid; grid-template-columns:1fr auto; gap:.15rem .4rem; align-items:center; padding:.55rem; }
        .fme-result-grid article span { color:var(--fme-muted); font-size:.62rem; }
        .fme-result-grid article strong { grid-column:1; font-size:.7rem; }
        .fme-result-grid article>div>a { display:flex; grid-column:2; grid-row:1/3; align-items:center; gap:.25rem; padding:.42rem .52rem; border-radius:.55rem; color:#fff; background:#be123c; font-size:.66rem; font-weight:850; }
        .fme-result-grid article>div>a svg { width:.85rem; }
        .fme-empty { display:grid; grid-column:1/-1; place-items:center; gap:.25rem; min-height:12rem; padding:1rem; color:var(--fme-muted); text-align:center; }
        .fme-empty svg { width:2.5rem; opacity:.45; }
        .fme-empty strong { color:var(--fme-ink); font-size:.82rem; }
        .fme-empty span { font-size:.7rem; }
        .fme-empty--compact { min-height:9rem; }
        .fme-pagination { margin-top:1rem; }
        @media (max-width:900px) { .fme-workspace { grid-template-columns:1fr; } .fme-editor-card { position:static; } }
        @media (max-width:640px) { .fme-hero { padding:1.05rem; } .fme-hero>svg { width:3rem; } .fme-heading { align-items:flex-start; flex-direction:column; } .fme-folder-grid { grid-template-columns:1fr; } .fme-photo-grid,.fme-result-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .fme-folder-bar { align-items:flex-start; flex-direction:column; } }
    </style>
</x-filament-panels::page>
