<x-filament-panels::page>
    @php
        $folder = $this->folder();
        $photos = $this->photos();
        $purchaseItemGroups = $this->purchaseItemOptions();
        $photoData = $photos->getCollection()->values()->map(fn ($photo): array => [
            'id' => $photo->id,
            'sequence' => $photo->urutan,
            'preview' => route('foto-barang.preview', [$folder, $photo]).'?v='.$photo->updated_at->getTimestamp(),
            'download' => route('foto-barang.download', [$folder, $photo]),
            'fileName' => $photo->fileName(),
            'capturedAt' => $photo->diambil_at->locale('id')->translatedFormat('d M Y, H:i').' WIB',
            'purchaseItemId' => $photo->relationLoaded('purchaseLink')
                ? $photo->purchaseLink?->pengeluaran_belanja_item_id
                : null,
        ])->all();
        $folderConfig = [
            'photos' => $photoData,
            'sessionUuid' => $folder->uuid,
            'archiveUrl' => route('foto-barang.archive', $folder),
            'selectedArchiveUrl' => route('foto-barang.selected-archive', $folder),
            'totalPhotos' => $folder->items_count,
        ];
    @endphp

    <div
        class="ff-page"
        x-data="fotoBarangFolder()"
        x-init="initialize(JSON.parse($refs.folderConfig.textContent))"
    >
        <script type="application/json" x-ref="folderConfig">@json($folderConfig)</script>

        <header class="ff-header">
            <div class="ff-header__copy">
                <a
                    class="ff-back"
                    href="{{ \App\Filament\Pages\FotoBarangMaps::getUrl(['session' => $folder->uuid]) }}"
                    wire:navigate
                >
                    <x-filament::icon icon="heroicon-m-arrow-left" /> Kembali ke Kamera Maps
                </a>
                <div class="ff-meta">
                    <span @class(['is-active' => $folder->isActive()])>{{ $folder->isActive() ? 'Sesi aktif' : 'Sesi selesai' }}</span>
                    <b>{{ $folder->code() }}</b>
                </div>
                <h1>{{ $folder->judul }}</h1>
                <p>{{ $folder->nama_lokasi }}</p>
                <small>{{ $folder->alamat }}</small>
            </div>

            <div class="ff-summary">
                <span>Isi folder</span>
                <strong x-text="totalPhotos"></strong>
                <small>foto tersimpan</small>
            </div>
        </header>

        @if ($folder->pengeluaranBelanjas->isNotEmpty())
            <section class="ff-purchase-links" aria-label="Transaksi belanja terkait">
                <span class="ff-purchase-links__icon"><x-filament::icon icon="heroicon-o-receipt-percent" /></span>
                <div>
                    <strong>Terhubung ke transaksi belanja</strong>
                    <span>Folder ini tetap aman jika hubungannya dilepas.</span>
                    <nav>
                        @foreach ($folder->pengeluaranBelanjas as $expense)
                            <a href="{{ \App\Filament\Resources\KalkulatorBelanjaResource::getUrl('view', ['record' => $expense->kalkulatorBelanja]) }}">
                                Supplier: {{ $expense->namaSupplier() }} · {{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah((int) $expense->nominal) }}
                            </a>
                        @endforeach
                    </nav>
                </div>
            </section>
        @endif

        <section class="ff-toolbar">
            <div>
                <strong>Galeri foto</strong>
                <span>Halaman {{ $photos->currentPage() }} dari {{ $photos->lastPage() }}</span>
            </div>
            <div>
                <button type="button" x-show="! selectionMode && photos.length" x-on:click="beginSelection()">
                    <x-filament::icon icon="heroicon-m-check-circle" /> Pilih Foto
                </button>
                @if ($folder->items_count > 0)
                    <button type="button" x-on:click="shareAll()" x-bind:disabled="shareAllBusy">
                        <x-filament::icon icon="heroicon-m-share" />
                        <span x-text="shareAllBusy ? 'Menyiapkan...' : 'Bagikan Semua ke WhatsApp'"></span>
                    </button>
                    <a href="{{ route('foto-barang.archive', $folder) }}">
                        <x-filament::icon icon="heroicon-m-arrow-down-tray" /> Unduh Semua ZIP
                    </a>
                @endif
            </div>
        </section>

        <div class="ff-message" x-show="actionMessage" x-text="actionMessage" x-cloak></div>

        <section class="ff-selection" x-show="selectionMode" x-cloak>
            <strong><span x-text="selectedIds.length"></span> foto dipilih</strong>
            <div>
                <button type="button" x-on:click="selectPage()">Pilih halaman ini</button>
                <button type="button" x-on:click="clearSelection()">Batal</button>
                @if ($purchaseItemGroups !== [])
                    <button type="button" class="is-label" x-on:click="requestLabel(selectedIds)" x-bind:disabled="! selectedIds.length">
                        <x-filament::icon icon="heroicon-m-tag" /> Tetapkan Barang
                    </button>
                @endif
                <button type="button" class="is-download" x-on:click="downloadSelected()" x-bind:disabled="! selectedIds.length || downloadBusy">
                    <x-filament::icon icon="heroicon-m-arrow-down-tray" />
                    <span x-text="downloadBusy ? 'Menyiapkan...' : 'Unduh Terpilih'"></span>
                </button>
                <button type="button" class="is-delete" x-on:click="requestDelete(selectedIds)" x-bind:disabled="! selectedIds.length">
                    <x-filament::icon icon="heroicon-m-trash" /> Hapus
                </button>
            </div>
        </section>

        @if ($photos->isEmpty())
            <div class="ff-empty">
                <x-filament::icon icon="heroicon-o-photo" />
                <strong>Folder belum memiliki foto</strong>
                <span>Ambil foto dari halaman Kamera Maps untuk mengisi folder ini.</span>
            </div>
        @else
            <section class="ff-grid">
                @foreach ($photos as $photo)
                    @php
                        $version = $photo->updated_at->getTimestamp();
                        $thumbnailUrl = route('foto-barang.thumbnail', [$folder, $photo]).'?v='.$version;
                        $downloadUrl = route('foto-barang.download', [$folder, $photo]);
                        $purchaseItem = $photo->relationLoaded('purchaseLink')
                            ? $photo->purchaseLink?->purchaseItem
                            : null;
                        $purchaseExpense = $purchaseItem?->pengeluaranBelanja;
                        $purchaseQuantity = $purchaseItem
                            ? rtrim(rtrim(number_format((float) $purchaseItem->jumlah, 3, ',', '.'), '0'), ',')
                            : null;
                    @endphp
                    <article wire:key="foto-folder-{{ $photo->id }}" class="ff-card" x-bind:class="isSelected({{ $photo->id }}) && 'is-selected'">
                        <button
                            type="button"
                            class="ff-card__image"
                            x-on:pointerdown="startLongPress({{ $photo->id }})"
                            x-on:pointerup="cancelLongPress()"
                            x-on:pointercancel="cancelLongPress()"
                            x-on:pointerleave="cancelLongPress()"
                            x-on:contextmenu.prevent
                            x-on:click="handlePhotoClick({{ $photo->id }}, {{ $loop->index }})"
                        >
                            <span class="ff-skeleton"><small>Pratinjau belum tersedia</small></span>
                            <img
                                src="{{ $thumbnailUrl }}"
                                alt="Foto barang urutan {{ $photo->urutan }}"
                                loading="{{ $loop->index < 2 ? 'eager' : 'lazy' }}"
                                decoding="async"
                                x-init="settleThumbnail($el)"
                                x-on:load="thumbnailReady($el)"
                                x-on:error="thumbnailFailed($el)"
                                draggable="false"
                            >
                            <span class="ff-sequence">#{{ str_pad((string) $photo->urutan, 2, '0', STR_PAD_LEFT) }}</span>
                            <span class="ff-check" x-show="selectionMode" x-cloak>
                                <i x-bind:class="isSelected({{ $photo->id }}) && 'is-checked'">
                                    <x-filament::icon icon="heroicon-m-check" x-show="isSelected({{ $photo->id }})" x-cloak />
                                </i>
                            </span>
                        </button>

                        <div class="ff-card__body">
                            <strong>{{ $photo->diambil_at->locale('id')->translatedFormat('d M Y, H:i') }} WIB</strong>
                            @if ($photo->processingCompleted())
                                <span class="ff-status is-completed">Siap dibagikan</span>
                            @elseif ($photo->processingFailed())
                                <span class="ff-status is-failed">Proses gagal, sumber tetap aman</span>
                            @else
                                <span class="ff-status is-pending">Sedang diproses</span>
                            @endif
                            <small>{{ $this->formatBytes((int) $photo->ukuran_hasil) }} · {{ $photo->lebar }}×{{ $photo->tinggi }} px</small>
                            @if ($purchaseItem)
                                <div class="ff-product-label">
                                    <span>{{ $purchaseExpense?->namaSupplier() }}</span>
                                    <strong>{{ $purchaseItem->namaBarang() }}</strong>
                                    <small>{{ $purchaseQuantity }} {{ $purchaseItem->satuan_snapshot }} × {{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah((int) $purchaseItem->harga_satuan) }}</small>
                                    <b>Subtotal {{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah((int) $purchaseItem->subtotal) }}</b>
                                </div>
                            @elseif ($purchaseItemGroups !== [])
                                <div class="ff-product-empty">Barang pada foto belum ditentukan</div>
                            @endif

                            @if ($purchaseItemGroups !== [])
                                <button
                                    type="button"
                                    class="ff-card__label-action"
                                    x-show="! selectionMode"
                                    x-cloak
                                    x-on:click="requestLabel({{ $photo->id }}, {{ $purchaseItem?->getKey() ?? 'null' }})"
                                >
                                    <x-filament::icon icon="heroicon-m-tag" />
                                    {{ $purchaseItem ? 'Ubah Barang' : 'Tentukan Barang' }}
                                </button>
                            @endif
                        </div>

                        <div class="ff-card__actions" x-show="! selectionMode" x-cloak>
                            <button type="button" x-on:click="sharePhoto(photos[{{ $loop->index }}])">
                                <x-filament::icon icon="heroicon-m-share" /> Bagikan
                            </button>
                            <a href="{{ $downloadUrl }}"><x-filament::icon icon="heroicon-m-arrow-down-tray" /> Unduh</a>
                            @if (! $photo->processingCompleted())
                                <button type="button" wire:click="retryPhotoProcessing({{ $photo->id }})">
                                    <x-filament::icon icon="heroicon-m-arrow-path" /> Ulangi
                                </button>
                            @endif
                            <button type="button" class="is-delete" x-on:click="requestDelete({{ $photo->id }})" aria-label="Hapus foto">
                                <x-filament::icon icon="heroicon-m-trash" />
                            </button>
                        </div>
                    </article>
                @endforeach
            </section>

            <div class="ff-pagination">
                {{ $photos->onEachSide(1)->links() }}
            </div>
        @endif

        <dialog
            class="ff-viewer"
            x-ref="viewerDialog"
            x-on:cancel.prevent="closeViewer()"
            x-on:close="viewerOpen = false; restoreScroll()"
            x-on:keydown.left.window="if (viewerOpen && ! confirmOpen) showPhoto(-1)"
            x-on:keydown.right.window="if (viewerOpen && ! confirmOpen) showPhoto(1)"
        >
            <header>
                <button type="button" x-on:click="closeViewer()"><x-filament::icon icon="heroicon-m-arrow-left" /> Kembali</button>
                <div>
                    <strong x-text="currentPhoto() ? '#' + String(currentPhoto().sequence).padStart(2, '0') : 'Foto'"></strong>
                    <span><b x-text="viewerIndex + 1"></b> dari <b x-text="photos.length"></b> di halaman ini</span>
                </div>
                <button type="button" class="is-delete" x-on:click="requestDelete(currentPhoto()?.id)" aria-label="Hapus foto">
                    <x-filament::icon icon="heroicon-m-trash" />
                </button>
            </header>
            <main x-on:touchstart.passive="beginSwipe($event)" x-on:touchend.passive="endSwipe($event)">
                <button type="button" class="is-prev" x-on:click="showPhoto(-1)" x-show="photos.length > 1"><x-filament::icon icon="heroicon-m-chevron-left" /></button>
                <template x-if="currentPhoto()">
                    <img
                        x-bind:src="currentPhoto().preview"
                        x-bind:alt="'Foto urutan ' + currentPhoto().sequence"
                        x-bind:class="! viewerLoading && ! viewerError && 'is-ready'"
                        x-on:load="viewerLoading = false; viewerError = false"
                        x-on:error="viewerLoading = false; viewerError = true"
                        decoding="async"
                    >
                </template>
                <div class="ff-viewer__loading" x-show="viewerLoading" x-cloak><span></span><small>Menyiapkan foto</small></div>
                <div class="ff-viewer__error" x-show="viewerError" x-cloak>Foto gagal dimuat.</div>
                <button type="button" class="is-next" x-on:click="showPhoto(1)" x-show="photos.length > 1"><x-filament::icon icon="heroicon-m-chevron-right" /></button>
            </main>
            <footer x-show="currentPhoto()">
                <span x-text="currentPhoto()?.capturedAt"></span>
                <div>
                    <button type="button" x-on:click="sharePhoto(currentPhoto())"><x-filament::icon icon="heroicon-m-share" /> Bagikan</button>
                    <a x-bind:href="currentPhoto()?.download"><x-filament::icon icon="heroicon-m-arrow-down-tray" /> Unduh</a>
                </div>
            </footer>
        </dialog>

        <dialog class="ff-confirm" x-ref="confirmDialog" x-on:cancel.prevent="closeConfirm()">
            <div>
                <span class="ff-confirm__icon"><x-filament::icon icon="heroicon-o-trash" /></span>
                <h2>Hapus <span x-text="confirmIds.length"></span> foto?</h2>
                <p>Foto akan dihapus permanen dari folder. Sistem akan memulihkan file bila transaksi gagal.</p>
                <label>
                    <span>Ketik <b>hapus</b> untuk melanjutkan</span>
                    <input x-ref="confirmInput" type="text" x-model="confirmInput" autocomplete="off" x-on:keydown.enter.prevent="confirmDelete()">
                </label>
                <div class="ff-confirm__actions">
                    <button type="button" x-on:click="closeConfirm()" x-bind:disabled="confirmBusy">Batal</button>
                    <button type="button" class="is-delete" x-on:click="confirmDelete()" x-bind:disabled="confirmBusy || confirmInput.trim().toLowerCase() !== 'hapus'">
                        <span x-text="confirmBusy ? 'Menghapus...' : 'Hapus Foto'"></span>
                    </button>
                </div>
            </div>
        </dialog>

        @if ($purchaseItemGroups !== [])
            <dialog class="ff-label-dialog" x-ref="labelDialog" x-on:cancel.prevent="closeLabelDialog()">
                <div>
                    <span class="ff-label-dialog__icon"><x-filament::icon icon="heroicon-o-tag" /></span>
                    <h2>Tentukan barang pada foto</h2>
                    <p><span x-text="labelIds.length"></span> foto akan diberi label dari transaksi belanja yang terhubung.</p>

                    <label>
                        <span>Barang belanja</span>
                        <select x-model="labelItemId" x-bind:disabled="labelBusy">
                            <option value="">Pilih barang</option>
                            @foreach ($purchaseItemGroups as $group)
                                <optgroup label="{{ $group['label'] }}">
                                    @foreach ($group['options'] as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>

                    <div class="ff-label-dialog__actions">
                        <button type="button" x-on:click="closeLabelDialog()" x-bind:disabled="labelBusy">Batal</button>
                        <button type="button" class="is-remove" x-show="labelHasExisting" x-on:click="saveLabel(true)" x-bind:disabled="labelBusy">
                            Lepas Label
                        </button>
                        <button type="button" class="is-save" x-on:click="saveLabel(false)" x-bind:disabled="labelBusy || labelItemId === ''">
                            <span x-text="labelBusy ? 'Menyimpan...' : 'Simpan'"></span>
                        </button>
                    </div>
                </div>
            </dialog>
        @endif
    </div>

    <style>
        [x-cloak]{display:none!important}.ff-page{--line:rgba(148,163,184,.25);--soft:rgba(148,163,184,.08);--ink:var(--gray-950);--muted:var(--gray-500);display:grid;gap:1rem}.dark .ff-page{--ink:#edf3fa;--muted:#9aa9bc}.ff-header{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:1.5rem;align-items:center;padding:1.35rem;border:1px solid var(--line);border-radius:1.15rem;background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(59,130,246,.08))}.ff-header__copy{min-width:0}.ff-back{display:inline-flex;align-items:center;gap:.35rem;margin-bottom:.8rem;color:#b77905;font-size:.72rem;font-weight:800;text-decoration:none}.ff-back svg{width:1rem}.ff-meta{display:flex;gap:.45rem;align-items:center}.ff-meta span,.ff-meta b{padding:.25rem .5rem;border-radius:999px;background:var(--soft);color:var(--muted);font-size:.6rem}.ff-meta span.is-active{color:#047857;background:rgba(16,185,129,.12)}.ff-header h1{margin:.45rem 0 .2rem;color:var(--ink);font-size:1.35rem;font-weight:850}.ff-header p,.ff-header small{display:block;margin:0;color:var(--muted);font-size:.7rem;line-height:1.5}.ff-summary{display:grid;place-items:center;min-width:8rem;padding:1rem;border:1px solid var(--line);border-radius:1rem;background:rgba(255,255,255,.45)}.dark .ff-summary{background:rgba(15,23,42,.45)}.ff-summary span,.ff-summary small{color:var(--muted);font-size:.62rem}.ff-summary strong{color:#d28a08;font-size:2rem}.ff-purchase-links{display:flex;align-items:flex-start;gap:.75rem;padding:.85rem 1rem;border:1px solid rgba(59,130,246,.24);border-radius:.9rem;background:rgba(59,130,246,.07)}.ff-purchase-links__icon{display:grid;place-items:center;width:2.25rem;height:2.25rem;flex:0 0 auto;border-radius:.7rem;color:#2563eb;background:rgba(59,130,246,.13)}.ff-purchase-links__icon svg{width:1.1rem}.ff-purchase-links>div{display:grid;gap:.15rem;min-width:0}.ff-purchase-links strong{color:var(--ink);font-size:.75rem}.ff-purchase-links span{color:var(--muted);font-size:.6rem}.ff-purchase-links nav{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.35rem}.ff-purchase-links nav a{padding:.3rem .5rem;border-radius:.5rem;color:#1d4ed8;background:rgba(59,130,246,.12);font-size:.62rem;font-weight:800;text-decoration:none}.dark .ff-purchase-links nav a{color:#93c5fd}.ff-toolbar,.ff-selection{display:flex;align-items:center;justify-content:space-between;gap:.8rem;padding:.85rem 1rem;border:1px solid var(--line);border-radius:.9rem}.ff-toolbar>div:first-child{display:grid}.ff-toolbar strong{color:var(--ink);font-size:.85rem}.ff-toolbar span{color:var(--muted);font-size:.62rem}.ff-toolbar>div:last-child,.ff-selection>div{display:flex;gap:.45rem}.ff-toolbar button,.ff-toolbar a,.ff-selection button{display:inline-flex;align-items:center;justify-content:center;gap:.3rem;min-height:2.35rem;padding:.45rem .7rem;border:1px solid var(--line);border-radius:.65rem;color:var(--ink);background:var(--soft);font-size:.65rem;font-weight:800;text-decoration:none}.ff-toolbar svg,.ff-selection svg{width:.95rem}.ff-selection{border-color:rgba(59,130,246,.35);background:rgba(59,130,246,.08)}.ff-selection strong{color:var(--ink);font-size:.72rem}.ff-selection .is-download{color:#1d4ed8}.ff-selection .is-delete{color:#dc2626}.ff-selection button:disabled{opacity:.45}.ff-message{padding:.7rem .85rem;border-radius:.7rem;color:#1d4ed8;background:rgba(59,130,246,.1);font-size:.68rem}.ff-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem}.ff-card{overflow:hidden;border:1px solid var(--line);border-radius:.9rem;background:var(--soft);transition:.18s}.ff-card.is-selected{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.16);transform:translateY(-1px)}.ff-card__image{position:relative;display:block;width:100%;aspect-ratio:4/5;overflow:hidden;padding:0;border:0;background:#0f172a;cursor:pointer;touch-action:pan-y;user-select:none;-webkit-touch-callout:none}.ff-card__image img{width:100%;height:100%;object-fit:contain;opacity:0;transition:opacity .25s}.ff-card__image img.is-ready{opacity:1}.ff-skeleton{position:absolute;inset:0;display:grid;place-items:center;background:linear-gradient(110deg,#111827 8%,#243247 22%,#111827 36%);background-size:220% 100%;animation:ff-pulse 1.2s linear infinite}.ff-skeleton small{display:none;color:#94a3b8;font-size:.6rem}.ff-card__image.is-ready .ff-skeleton{display:none}.ff-card__image.is-failed .ff-skeleton{animation:none}.ff-card__image.is-failed .ff-skeleton small{display:block}.ff-sequence{position:absolute;z-index:2;top:.5rem;left:.5rem;padding:.28rem .42rem;border-radius:.45rem;color:#211607;background:#fbbf24;font-size:.61rem;font-weight:850}.ff-check{position:absolute;z-index:2;top:.5rem;right:.5rem;display:grid;place-items:center;width:1.8rem;height:1.8rem;border-radius:50%;background:rgba(15,23,42,.72)}.ff-check i{display:grid;place-items:center;width:1.2rem;height:1.2rem;border:2px solid #fff;border-radius:50%;color:#fff}.ff-check i.is-checked{border-color:#2563eb;background:#2563eb}.ff-check svg{width:.75rem}.ff-card__body{display:grid;gap:.2rem;padding:.7rem}.ff-card__body strong{color:var(--ink);font-size:.68rem}.ff-card__body small{color:var(--muted);font-size:.58rem}.ff-status{width:max-content;padding:.2rem .38rem;border-radius:999px;font-size:.56rem;font-weight:800}.ff-status.is-completed{color:#047857;background:rgba(16,185,129,.12)}.ff-status.is-pending{color:#b45309;background:rgba(245,158,11,.14)}.ff-status.is-failed{color:#dc2626;background:rgba(239,68,68,.12)}.ff-card__actions{display:flex;gap:.35rem;padding:0 .6rem .65rem}.ff-card__actions button,.ff-card__actions a{display:flex;flex:1;align-items:center;justify-content:center;gap:.25rem;min-height:2rem;padding:.3rem;border:1px solid var(--line);border-radius:.55rem;color:var(--ink);background:var(--soft);font-size:.59rem;font-weight:750;text-decoration:none}.ff-card__actions .is-delete{flex:0 0 2rem;color:#dc2626}.ff-card__actions svg{width:.85rem}.ff-pagination{overflow-x:auto}.ff-empty{display:grid;place-items:center;gap:.35rem;padding:3rem;border:1px dashed var(--line);border-radius:1rem;color:var(--muted);text-align:center}.ff-empty svg{width:2.2rem}.ff-empty strong{color:var(--ink);font-size:.85rem}.ff-empty span{font-size:.65rem}.ff-viewer{position:fixed;inset:0;grid-template-rows:auto minmax(0,1fr) auto;width:100%;max-width:none;height:100dvh;max-height:none;margin:0;padding:0;border:0;color:#fff;background:#03060a}.ff-viewer[open]{display:grid}.ff-viewer::backdrop{background:#03060a}.ff-viewer header{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:.6rem;padding:calc(.65rem + env(safe-area-inset-top)) .8rem .65rem;background:#0b111a}.ff-viewer header button,.ff-viewer footer button,.ff-viewer footer a{display:flex;align-items:center;justify-content:center;gap:.3rem;min-height:2.35rem;padding:.45rem .65rem;border:1px solid #334155;border-radius:.65rem;color:#fff;background:#172131;font-size:.64rem;font-weight:800;text-decoration:none}.ff-viewer header button:first-child{justify-self:start}.ff-viewer header button.is-delete{justify-self:end;width:2.35rem;color:#fecaca}.ff-viewer header svg,.ff-viewer footer svg{width:.95rem}.ff-viewer header div{display:grid;justify-items:center}.ff-viewer header strong{font-size:.76rem}.ff-viewer header span{color:#94a3b8;font-size:.58rem}.ff-viewer main{position:relative;display:grid;place-items:center;min-height:0;overflow:hidden;padding:.4rem}.ff-viewer main>img{max-width:100%;max-height:100%;opacity:0;object-fit:contain;transition:opacity .25s}.ff-viewer main>img.is-ready{opacity:1}.ff-viewer main>button{position:absolute;z-index:2;top:50%;display:grid;place-items:center;width:2.7rem;height:2.7rem;border:1px solid rgba(255,255,255,.2);border-radius:50%;color:#fff;background:rgba(8,15,24,.75);transform:translateY(-50%)}.ff-viewer main>button svg{width:1.2rem}.ff-viewer main>.is-prev{left:.7rem}.ff-viewer main>.is-next{right:.7rem}.ff-viewer__loading,.ff-viewer__error{position:absolute;inset:0;display:grid;place-content:center;justify-items:center;gap:.5rem;background:#03060a;color:#94a3b8;font-size:.65rem}.ff-viewer__loading span{width:min(76vw,28rem);aspect-ratio:4/5;border-radius:1rem;background:linear-gradient(110deg,#0e1724 8%,#1c2a3d 22%,#0e1724 36%);background-size:220% 100%;animation:ff-pulse 1.2s linear infinite}.ff-viewer footer{display:flex;align-items:center;justify-content:space-between;gap:.7rem;padding:.65rem .8rem calc(.7rem + env(safe-area-inset-bottom));background:#0b111a}.ff-viewer footer>span{color:#94a3b8;font-size:.62rem}.ff-viewer footer>div{display:flex;gap:.45rem}.ff-confirm{position:fixed;inset:0;place-items:center;width:100%;max-width:none;height:100dvh;max-height:none;margin:0;padding:1rem;border:0;background:transparent}.ff-confirm[open]{display:grid}.ff-confirm::backdrop{background:rgba(3,7,13,.68);backdrop-filter:blur(9px)}.ff-confirm>div{display:grid;justify-items:center;max-width:25rem;width:100%;padding:1.35rem;border:1px solid rgba(255,255,255,.14);border-radius:1.2rem;color:#eef4fb;background:linear-gradient(155deg,#1c2736,#0b121c);text-align:center}.ff-confirm__icon{display:grid;place-items:center;width:3.5rem;height:3.5rem;border-radius:1rem;color:#fecaca;background:#521923}.ff-confirm__icon svg{width:1.6rem}.ff-confirm h2{margin:.8rem 0 0;font-size:1.05rem}.ff-confirm p{margin:.4rem 0 0;color:#aebaca;font-size:.68rem;line-height:1.5}.ff-confirm label{display:grid;gap:.35rem;width:100%;margin-top:1rem;text-align:left}.ff-confirm label span{color:#cbd5e1;font-size:.65rem}.ff-confirm label b{color:#fca5a5}.ff-confirm input{width:100%;padding:.7rem;border:1px solid #45566b;border-radius:.65rem;color:#fff;background:#0c1420}.ff-confirm__actions{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;width:100%;margin-top:1rem}.ff-confirm__actions button{min-height:2.5rem;border:1px solid #45566b;border-radius:.65rem;color:#fff;background:#172131;font-size:.68rem;font-weight:800}.ff-confirm__actions button.is-delete{border-color:#ef4444;background:#b91c1c}.ff-confirm__actions button:disabled{opacity:.45}@media(max-width:900px){.ff-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:640px){.ff-header{grid-template-columns:1fr;padding:1rem}.ff-summary{grid-template-columns:auto auto auto;gap:.4rem;justify-content:start;min-width:0;padding:.7rem}.ff-summary strong{font-size:1.1rem}.ff-toolbar,.ff-selection{align-items:stretch;flex-direction:column}.ff-toolbar>div:last-child,.ff-selection>div{display:grid;grid-template-columns:1fr 1fr}.ff-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem}.ff-viewer main>button{display:none}.ff-viewer footer{align-items:stretch;flex-direction:column}.ff-viewer footer>div{display:grid;grid-template-columns:1fr 1fr}}@media(max-width:390px){.ff-grid{grid-template-columns:1fr}}@keyframes ff-pulse{from{background-position:100% 0}to{background-position:-100% 0}}@media(prefers-reduced-motion:reduce){.ff-skeleton,.ff-viewer__loading span{animation:none}}
    </style>

    <style>
        .ff-selection .is-label {
            color: #7c3aed;
        }

        .ff-product-label {
            display: grid;
            gap: .18rem;
            margin-top: .42rem;
            padding: .55rem;
            border: 1px solid rgba(139, 92, 246, .22);
            border-radius: .62rem;
            background: rgba(139, 92, 246, .08);
        }

        .ff-product-label > span {
            overflow: hidden;
            color: #7c3aed;
            font-size: .55rem;
            font-weight: 850;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ff-product-label > strong {
            font-size: .67rem;
        }

        .ff-product-label > small {
            line-height: 1.4;
        }

        .ff-product-label > b {
            color: #b45309;
            font-size: .59rem;
        }

        .ff-product-empty {
            margin-top: .42rem;
            padding: .5rem;
            border: 1px dashed var(--line);
            border-radius: .58rem;
            color: var(--muted);
            font-size: .57rem;
            text-align: center;
        }

        .ff-card__label-action {
            display: inline-flex;
            width: 100%;
            min-height: 2rem;
            align-items: center;
            justify-content: center;
            gap: .3rem;
            margin-top: .45rem;
            padding: .35rem .45rem;
            border: 1px solid rgba(139, 92, 246, .28);
            border-radius: .58rem;
            color: #6d28d9;
            background: rgba(139, 92, 246, .08);
            font-size: .59rem;
            font-weight: 850;
        }

        .ff-card__label-action svg {
            width: .85rem;
        }

        .dark .ff-product-label,
        .dark .ff-card__label-action {
            color: #c4b5fd;
            background: rgba(109, 40, 217, .14);
        }

        .ff-label-dialog {
            position: fixed;
            inset: 0;
            width: 100%;
            max-width: none;
            height: 100dvh;
            max-height: none;
            margin: 0;
            padding: 1rem;
            border: 0;
            background: transparent;
        }

        .ff-label-dialog[open] {
            display: grid;
            place-items: center;
        }

        .ff-label-dialog::backdrop {
            background: rgba(3, 7, 18, .68);
            backdrop-filter: blur(9px);
        }

        .ff-label-dialog > div {
            display: grid;
            justify-items: center;
            width: min(100%, 28rem);
            padding: 1.35rem;
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 1.2rem;
            color: #eef4fb;
            background: linear-gradient(155deg, #1c2736, #0b121c);
            text-align: center;
        }

        .ff-label-dialog__icon {
            display: grid;
            width: 3.4rem;
            height: 3.4rem;
            place-items: center;
            border-radius: 1rem;
            color: #ddd6fe;
            background: #4c1d95;
        }

        .ff-label-dialog__icon svg {
            width: 1.55rem;
        }

        .ff-label-dialog h2 {
            margin: .8rem 0 0;
            font-size: 1.05rem;
        }

        .ff-label-dialog p {
            margin: .35rem 0 0;
            color: #aebaca;
            font-size: .68rem;
            line-height: 1.5;
        }

        .ff-label-dialog label {
            display: grid;
            gap: .38rem;
            width: 100%;
            margin-top: 1rem;
            text-align: left;
        }

        .ff-label-dialog label > span {
            color: #cbd5e1;
            font-size: .65rem;
            font-weight: 750;
        }

        .ff-label-dialog select {
            width: 100%;
            min-height: 2.75rem;
            padding: .65rem .75rem;
            border: 1px solid #45566b;
            border-radius: .68rem;
            color: #fff;
            background: #0c1420;
            font-size: .7rem;
        }

        .ff-label-dialog select option,
        .ff-label-dialog select optgroup {
            color: #fff;
            background: #0c1420;
        }

        .ff-label-dialog__actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .55rem;
            width: 100%;
            margin-top: 1rem;
        }

        .ff-label-dialog__actions button {
            min-height: 2.5rem;
            padding: .45rem;
            border: 1px solid #45566b;
            border-radius: .68rem;
            color: #fff;
            background: #172131;
            font-size: .66rem;
            font-weight: 850;
        }

        .ff-label-dialog__actions .is-remove {
            border-color: #ef4444;
            color: #fecaca;
            background: #7f1d1d;
        }

        .ff-label-dialog__actions .is-save {
            border-color: #7c3aed;
            background: #6d28d9;
        }

        .ff-label-dialog__actions button:disabled {
            cursor: wait;
            opacity: .45;
        }

        @media (max-width: 420px) {
            .ff-label-dialog__actions {
                grid-template-columns: 1fr 1fr;
            }

            .ff-label-dialog__actions .is-save {
                grid-column: 1 / -1;
                grid-row: 1;
            }
        }
    </style>
</x-filament-panels::page>
