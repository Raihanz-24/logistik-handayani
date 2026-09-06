<x-filament-panels::page>
    @php
        $selectedSession = $this->selectedSession();
        $selectedPhoto = $this->selectedPhoto();
        $sessions = $selectedSession ? null : $this->sessions();
        $originalPhotos = $selectedSession ? $this->originalPhotos() : null;
        $editedPhotos = $selectedSession ? $this->editedPhotos() : null;
        $destinationSessions = $selectedSession ? $this->copyDestinationSessions() : collect();
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

            <section
                class="fme-panel fme-results"
                x-data="{
                    resultPreviewOpen: false,
                    resultPhotoIndex: 0,
                    resultPhotos: [],
                    resultImageLoading: false,
                    resultTouchStartX: null,
                    copyEditIds: [],
                    copyPhotoTitle: '',
                    copyDestinationSessionId: {{ (int) $selectedSession->getKey() }},
                    copyBusy: false,
                    copyError: '',
                    selectionMode: false,
                    selectedEditIds: [],
                    resultLongPressTimer: null,
                    resultLongPressTriggered: false,
                    suppressResultClickUntil: 0,
                    syncResultPhotos() {
                        this.resultPhotos = Array.from(this.$root.querySelectorAll('[data-edit-preview]')).map((button) => ({
                            preview: button.dataset.preview,
                            download: button.dataset.download,
                            title: button.dataset.title,
                            editId: Number(button.dataset.editId),
                        }));
                    },
                    currentResultPhoto() {
                        return this.resultPhotos[this.resultPhotoIndex] || null;
                    },
                    openResultPreview(index) {
                        this.syncResultPhotos();
                        if (! this.resultPhotos[index]) return;
                        this.resultPhotoIndex = index;
                        this.resultImageLoading = true;
                        this.resultPreviewOpen = true;
                        this.$nextTick(() => {
                            const dialog = this.$refs.resultPreviewDialog;
                            if (dialog && ! dialog.open) dialog.showModal();
                            document.body.style.overflow = 'hidden';
                        });
                    },
                    closeResultPreview() {
                        const dialog = this.$refs.resultPreviewDialog;
                        if (dialog?.open) dialog.close();
                        this.resultPreviewOpen = false;
                        this.resultImageLoading = false;
                        this.resultTouchStartX = null;
                        document.body.style.overflow = '';
                    },
                    moveResultPreview(direction) {
                        if (this.resultPhotos.length < 2) return;
                        this.resultPhotoIndex = (this.resultPhotoIndex + direction + this.resultPhotos.length) % this.resultPhotos.length;
                        this.resultImageLoading = true;
                    },
                    startResultSwipe(event) {
                        this.resultTouchStartX = event.changedTouches?.[0]?.clientX ?? null;
                    },
                    endResultSwipe(event) {
                        const endX = event.changedTouches?.[0]?.clientX;
                        if (this.resultTouchStartX === null || endX === undefined) return;
                        const distance = endX - this.resultTouchStartX;
                        this.resultTouchStartX = null;
                        if (Math.abs(distance) < 45) return;
                        this.moveResultPreview(distance < 0 ? 1 : -1);
                    },
                    isResultSelected(index) {
                        const button = this.$root.querySelectorAll('[data-edit-preview]')[index];
                        const editId = Number(button?.dataset.editId);
                        return editId > 0 && this.selectedEditIds.includes(editId);
                    },
                    toggleResultSelection(index) {
                        this.syncResultPhotos();
                        const editId = Number(this.resultPhotos[index]?.editId);
                        if (! editId) return;
                        if (this.selectedEditIds.includes(editId)) {
                            this.selectedEditIds = this.selectedEditIds.filter((id) => id !== editId);
                        } else {
                            this.selectedEditIds = [...this.selectedEditIds, editId];
                        }
                        this.selectionMode = this.selectedEditIds.length > 0;
                    },
                    startResultLongPress(index) {
                        if (this.selectionMode) return;
                        window.clearTimeout(this.resultLongPressTimer);
                        this.resultLongPressTriggered = false;
                        this.resultLongPressTimer = window.setTimeout(() => {
                            this.resultLongPressTriggered = true;
                            this.selectionMode = true;
                            this.toggleResultSelection(index);
                            navigator.vibrate?.(30);
                        }, 520);
                    },
                    cancelResultLongPress() {
                        window.clearTimeout(this.resultLongPressTimer);
                        this.resultLongPressTimer = null;
                        if (this.resultLongPressTriggered) {
                            this.suppressResultClickUntil = Date.now() + 600;
                        }
                    },
                    handleResultPhotoClick(index) {
                        if (Date.now() < this.suppressResultClickUntil) {
                            this.resultLongPressTriggered = false;
                            return;
                        }
                        if (this.selectionMode) {
                            this.toggleResultSelection(index);
                            return;
                        }
                        this.openResultPreview(index);
                    },
                    selectAllResults() {
                        this.syncResultPhotos();
                        const pageIds = this.resultPhotos
                            .map((photo) => Number(photo.editId))
                            .filter((id) => id > 0);
                        this.selectedEditIds = [...new Set([...this.selectedEditIds, ...pageIds])].slice(0, 100);
                        this.selectionMode = this.selectedEditIds.length > 0;
                    },
                    clearResultSelection() {
                        this.cancelResultLongPress();
                        this.selectionMode = false;
                        this.selectedEditIds = [];
                        this.resultLongPressTriggered = false;
                    },
                    openBulkCopyDialog() {
                        if (this.selectedEditIds.length < 1) return;
                        this.openCopyDialog(
                            this.selectedEditIds,
                            `${this.selectedEditIds.length} foto hasil edit terpilih`,
                        );
                    },
                    openCopyDialogFromIndex(index) {
                        this.syncResultPhotos();
                        const photo = this.resultPhotos[index];
                        if (! photo?.editId) return;
                        this.openCopyDialog(photo.editId, photo.title);
                    },
                    openCopyDialog(editIds, title) {
                        const normalizedIds = (Array.isArray(editIds) ? editIds : [editIds])
                            .map((id) => Number(id))
                            .filter((id) => Number.isInteger(id) && id > 0);
                        if (normalizedIds.length < 1) return;
                        if (this.resultPreviewOpen) this.closeResultPreview();
                        this.copyEditIds = [...new Set(normalizedIds)].slice(0, 100);
                        this.copyPhotoTitle = title || 'Hasil edit foto';
                        this.copyDestinationSessionId = {{ (int) $selectedSession->getKey() }};
                        this.copyError = '';
                        this.$nextTick(() => {
                            const dialog = this.$refs.copyResultDialog;
                            if (dialog && ! dialog.open) dialog.showModal();
                            document.body.style.overflow = 'hidden';
                        });
                    },
                    closeCopyDialog() {
                        const dialog = this.$refs.copyResultDialog;
                        if (dialog?.open) dialog.close();
                        this.copyEditIds = [];
                        this.copyPhotoTitle = '';
                        this.copyBusy = false;
                        this.copyError = '';
                        document.body.style.overflow = '';
                    },
                    async confirmResultCopy() {
                        const destinationId = Number(this.copyDestinationSessionId);
                        if (! Number.isInteger(destinationId) || destinationId < 1) {
                            this.copyError = 'Pilih folder tujuan terlebih dahulu.';
                            return;
                        }
                        if (this.copyEditIds.length < 1 || this.copyBusy) return;

                        this.copyBusy = true;
                        this.copyError = '';

                        try {
                            const result = await $wire.copyEditedPhotos(this.copyEditIds, destinationId);
                            if (! result?.copied) {
                                this.copyError = result?.message || 'Foto belum berhasil disalin.';
                                return;
                            }
                            this.closeCopyDialog();
                            this.clearResultSelection();
                        } catch (error) {
                            this.copyError = 'Koneksi terputus. Foto asli tetap aman, silakan coba kembali.';
                        } finally {
                            this.copyBusy = false;
                        }
                    },
                    destroy() {
                        this.cancelResultLongPress();
                        document.body.style.overflow = '';
                    },
                }"
                x-on:keydown.left.window="if (resultPreviewOpen) moveResultPreview(-1)"
                x-on:keydown.right.window="if (resultPreviewOpen) moveResultPreview(1)"
            >
                <div class="fme-heading">
                    <div>
                        <span>Folder terpisah</span>
                        <h2>Hasil Edit</h2>
                    </div>
                    <p>Tekan lama salah satu foto untuk memilih dan menyalin beberapa foto sekaligus.</p>
                </div>

                <div class="fme-selection-bar" x-show="selectionMode" x-cloak>
                    <div>
                        <x-filament::icon icon="heroicon-m-check-circle" />
                        <strong><span x-text="selectedEditIds.length"></span> foto dipilih</strong>
                    </div>
                    <div>
                        <button type="button" x-on:click="selectAllResults()">Pilih Semua di Halaman</button>
                        <button type="button" x-on:click="clearResultSelection()">Batal</button>
                        <button type="button" class="is-primary" x-on:click="openBulkCopyDialog()">
                            <x-filament::icon icon="heroicon-m-folder-arrow-down" /> Salin Terpilih
                        </button>
                    </div>
                </div>

                <div class="fme-result-grid">
                    @forelse ($editedPhotos as $edit)
                        <article x-bind:class="isResultSelected({{ $loop->index }}) && 'is-selected'">
                            <button
                                type="button"
                                class="fme-result-preview"
                                data-edit-preview
                                data-edit-id="{{ $edit->getKey() }}"
                                data-preview="{{ route('foto-barang.edit-preview', [$selectedSession, $edit]) }}?v={{ $edit->updated_at->getTimestamp() }}"
                                data-download="{{ route('foto-barang.edit-download', [$selectedSession, $edit]) }}"
                                data-title="Foto #{{ str_pad((string) ($edit->photo?->urutan ?? 0), 2, '0', STR_PAD_LEFT) }} · {{ $edit->waktu_baru?->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB"
                                x-on:pointerdown="startResultLongPress({{ $loop->index }})"
                                x-on:pointerup="cancelResultLongPress()"
                                x-on:pointercancel="cancelResultLongPress()"
                                x-on:pointerleave="cancelResultLongPress()"
                                x-on:contextmenu.prevent
                                x-on:click="handleResultPhotoClick({{ $loop->index }})"
                                aria-label="Preview hasil edit foto {{ $edit->photo?->urutan }}"
                            >
                                <img src="{{ route('foto-barang.edit-preview', [$selectedSession, $edit]) }}?v={{ $edit->updated_at->getTimestamp() }}" alt="Hasil foto {{ $edit->photo?->urutan }}" loading="lazy" draggable="false">
                                <span class="fme-result-selection" x-show="selectionMode" x-cloak>
                                    <i x-bind:class="isResultSelected({{ $loop->index }}) && 'is-checked'">
                                        <x-filament::icon icon="heroicon-m-check" x-show="isResultSelected({{ $loop->index }})" x-cloak />
                                    </i>
                                </span>
                            </button>
                            <div class="fme-result-meta">
                                <span>Foto #{{ str_pad((string) ($edit->photo?->urutan ?? 0), 2, '0', STR_PAD_LEFT) }}</span>
                                <strong>{{ $edit->waktu_baru?->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</strong>
                                <div class="fme-result-actions" x-show="! selectionMode" x-cloak>
                                    <button type="button" x-on:click="openCopyDialogFromIndex({{ $loop->index }})">
                                        <x-filament::icon icon="heroicon-m-folder-arrow-down" /> Salin ke Folder
                                    </button>
                                    <a href="{{ route('foto-barang.edit-download', [$selectedSession, $edit]) }}">
                                        <x-filament::icon icon="heroicon-m-arrow-down-tray" /> Unduh
                                    </a>
                                </div>
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

                <dialog
                    class="fme-result-dialog"
                    x-ref="resultPreviewDialog"
                    x-on:cancel.prevent="closeResultPreview()"
                    x-on:close="resultPreviewOpen = false; resultImageLoading = false; document.body.style.overflow = ''"
                    x-on:click.self="closeResultPreview()"
                >
                    <div
                        class="fme-result-dialog__panel"
                        x-on:touchstart.passive="startResultSwipe($event)"
                        x-on:touchend.passive="endResultSwipe($event)"
                    >
                        <header>
                            <button type="button" class="fme-result-dialog__back" x-on:click="closeResultPreview()">
                                <x-filament::icon icon="heroicon-m-arrow-left" /> Kembali
                            </button>
                            <span x-text="resultPhotos.length ? `${resultPhotoIndex + 1} / ${resultPhotos.length}` : ''"></span>
                        </header>

                        <main>
                            <div class="fme-result-dialog__loader" x-show="resultImageLoading" x-cloak></div>
                            <img
                                x-bind:src="currentResultPhoto()?.preview || ''"
                                x-bind:alt="currentResultPhoto()?.title || 'Preview hasil edit'"
                                x-on:load="resultImageLoading = false"
                                x-on:error="resultImageLoading = false"
                            >
                            <button
                                type="button"
                                class="fme-result-dialog__nav is-prev"
                                x-show="resultPhotos.length > 1"
                                x-on:click="moveResultPreview(-1)"
                                aria-label="Foto sebelumnya"
                            ><x-filament::icon icon="heroicon-m-chevron-left" /></button>
                            <button
                                type="button"
                                class="fme-result-dialog__nav is-next"
                                x-show="resultPhotos.length > 1"
                                x-on:click="moveResultPreview(1)"
                                aria-label="Foto berikutnya"
                            ><x-filament::icon icon="heroicon-m-chevron-right" /></button>
                        </main>

                        <footer>
                            <strong x-text="currentResultPhoto()?.title || ''"></strong>
                            <div>
                                <button
                                    type="button"
                                    x-on:click="openCopyDialog(currentResultPhoto()?.editId, currentResultPhoto()?.title)"
                                >
                                    <x-filament::icon icon="heroicon-m-folder-arrow-down" /> Salin ke Folder
                                </button>
                                <a x-bind:href="currentResultPhoto()?.download || '#'">
                                    <x-filament::icon icon="heroicon-m-arrow-down-tray" /> Unduh Foto
                                </a>
                            </div>
                        </footer>
                    </div>
                </dialog>

                <dialog
                    class="fme-copy-dialog"
                    x-ref="copyResultDialog"
                    x-on:cancel.prevent="if (! copyBusy) closeCopyDialog()"
                    x-on:close="document.body.style.overflow = ''"
                    wire:ignore
                >
                    <div class="fme-copy-dialog__panel">
                        <div class="fme-copy-dialog__icon"><x-filament::icon icon="heroicon-o-folder-arrow-down" /></div>
                        <div>
                            <span>Salin tanpa mengubah file asli</span>
                            <h3>Salin ke Folder Foto Maps</h3>
                            <p x-text="copyPhotoTitle"></p>
                        </div>

                        <label>
                            <span>Folder tujuan</span>
                            <select x-model.number="copyDestinationSessionId" x-bind:disabled="copyBusy">
                                <option value="">Pilih folder tujuan</option>
                                @foreach ($destinationSessions as $destinationSession)
                                    <option value="{{ $destinationSession->getKey() }}">
                                        {{ $destinationSession->judul }} · {{ $destinationSession->code() }} · {{ $destinationSession->items_count }} foto
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <p class="fme-copy-dialog__safe">
                            <x-filament::icon icon="heroicon-o-shield-check" />
                            File disalin tanpa kompres ulang. Foto asli dan hasil edit tetap tersimpan.
                        </p>
                        <p class="fme-copy-dialog__error" x-show="copyError" x-text="copyError" x-cloak></p>

                        <footer>
                            <button type="button" class="is-cancel" x-on:click="closeCopyDialog()" x-bind:disabled="copyBusy">Batal</button>
                            <button type="button" class="is-copy" x-on:click="confirmResultCopy()" x-bind:disabled="copyBusy">
                                <x-filament::icon icon="heroicon-m-folder-arrow-down" />
                                <span x-text="copyBusy ? 'Menyalin...' : 'Salin Foto'"></span>
                            </button>
                        </footer>
                    </div>
                </dialog>
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
        .fme-selection-bar { display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin-bottom:1rem; padding:.65rem .75rem; border:1px solid #fda4af; border-radius:.8rem; background:#fff1f2; }
        .dark .fme-selection-bar { border-color:#6b2638; background:#351521; }
        .fme-selection-bar>div { display:flex; align-items:center; flex-wrap:wrap; gap:.4rem; }
        .fme-selection-bar>div:first-child { color:#9f1239; font-size:.73rem; }
        .dark .fme-selection-bar>div:first-child { color:#fecdd3; }
        .fme-selection-bar>div:first-child svg { width:1.1rem; }
        .fme-selection-bar button { min-height:2.1rem; padding:.38rem .55rem; border:1px solid #fda4af; border-radius:.55rem; color:#9f1239; background:#fff; font-size:.63rem; font-weight:850; }
        .dark .fme-selection-bar button { border-color:#6b2638; color:#fecdd3; background:#24111a; }
        .fme-selection-bar button.is-primary { display:flex; align-items:center; gap:.25rem; border-color:#be123c; color:#fff; background:#be123c; }
        .fme-selection-bar button.is-primary svg { width:.9rem; }
        .fme-result-grid article { overflow:hidden; border:2px solid transparent; border-radius:.8rem; background:var(--fme-soft); box-shadow:0 0 0 1px var(--fme-line); transition:border-color .15s,box-shadow .15s,transform .15s; }
        .fme-result-grid article.is-selected { border-color:#e11d48; box-shadow:0 0 0 3px rgba(225,29,72,.15); transform:translateY(-1px); }
        .fme-result-preview { position:relative; display:block; width:100%; padding:0; border:0; background:transparent; cursor:zoom-in; touch-action:pan-y; user-select:none; -webkit-touch-callout:none; }
        .fme-result-selection { position:absolute; z-index:2; top:.45rem; right:.45rem; display:grid; place-items:center; width:1.7rem; height:1.7rem; padding:0!important; border-radius:50%; background:rgba(15,23,42,.7); }
        .fme-result-selection i { display:grid; place-items:center; width:1.2rem; height:1.2rem; border:2px solid #fff; border-radius:50%; color:#fff; background:transparent; }
        .fme-result-selection i.is-checked { border-color:#e11d48; background:#e11d48; }
        .fme-result-selection svg { width:.75rem; }
        .fme-result-grid article>.fme-result-meta { display:grid; gap:.18rem; padding:.55rem; }
        .fme-result-grid article span { color:var(--fme-muted); font-size:.62rem; }
        .fme-result-grid article strong { font-size:.7rem; }
        .fme-result-actions { display:grid; grid-template-columns:1fr auto; gap:.35rem; margin-top:.35rem; }
        .fme-result-actions button,.fme-result-actions a { display:flex; align-items:center; justify-content:center; gap:.25rem; min-height:2.15rem; padding:.38rem .48rem; border:1px solid #fda4af; border-radius:.55rem; color:#9f1239; background:#fff1f2; font-size:.62rem; font-weight:850; }
        .dark .fme-result-actions button,.dark .fme-result-actions a { border-color:#6b2638; color:#fecdd3; background:#351521; }
        .fme-result-actions a { border-color:#be123c; color:#fff; background:#be123c; }
        .fme-result-actions svg { width:.85rem; }
        .fme-empty { display:grid; grid-column:1/-1; place-items:center; gap:.25rem; min-height:12rem; padding:1rem; color:var(--fme-muted); text-align:center; }
        .fme-empty svg { width:2.5rem; opacity:.45; }
        .fme-empty strong { color:var(--fme-ink); font-size:.82rem; }
        .fme-empty span { font-size:.7rem; }
        .fme-empty--compact { min-height:9rem; }
        .fme-pagination { margin-top:1rem; }
        .fme-result-dialog { width:100%; max-width:none; height:100%; max-height:none; margin:0; padding:0; border:0; color:#fff; background:transparent; }
        .fme-result-dialog::backdrop { background:rgba(2,6,12,.94); backdrop-filter:blur(10px); }
        .fme-result-dialog__panel { display:grid; grid-template-rows:auto minmax(0,1fr) auto; width:100%; height:100dvh; padding:calc(.75rem + env(safe-area-inset-top)) .75rem calc(.75rem + env(safe-area-inset-bottom)); background:rgba(2,6,12,.88); }
        .fme-result-dialog__panel>header,.fme-result-dialog__panel>footer { display:flex; align-items:center; justify-content:space-between; gap:.75rem; width:min(100%,70rem); margin:auto; }
        .fme-result-dialog__panel>header { padding-bottom:.65rem; }
        .fme-result-dialog__panel>header>span { color:#cbd5e1; font-size:.72rem; font-weight:850; }
        .fme-result-dialog__back,.fme-result-dialog__panel>footer button,.fme-result-dialog__panel>footer a { display:flex; align-items:center; justify-content:center; gap:.35rem; min-height:2.45rem; padding:.5rem .7rem; border:1px solid #475569; border-radius:.7rem; color:#fff; background:#172033; font-size:.72rem; font-weight:850; }
        .fme-result-dialog__back svg,.fme-result-dialog__panel>footer button svg,.fme-result-dialog__panel>footer a svg { width:1rem; }
        .fme-result-dialog__panel>main { position:relative; display:grid; place-items:center; overflow:hidden; min-height:0; touch-action:pan-y; }
        .fme-result-dialog__panel>main>img { display:block; width:100%; height:100%; object-fit:contain; user-select:none; -webkit-user-drag:none; }
        .fme-result-dialog__loader { position:absolute; z-index:1; width:min(78vw,24rem); aspect-ratio:3/4; border-radius:.85rem; background:linear-gradient(100deg,#111827 20%,#293548 45%,#111827 70%); background-size:220% 100%; animation:fme-result-loading 1.15s ease-in-out infinite; }
        .fme-result-dialog__nav { position:absolute; z-index:2; top:50%; display:grid; place-items:center; width:2.75rem; height:2.75rem; border:1px solid rgba(255,255,255,.2); border-radius:50%; color:#fff; background:rgba(15,23,42,.78); transform:translateY(-50%); }
        .fme-result-dialog__nav svg { width:1.2rem; }
        .fme-result-dialog__nav.is-prev { left:.4rem; }
        .fme-result-dialog__nav.is-next { right:.4rem; }
        .fme-result-dialog__panel>footer { padding-top:.65rem; }
        .fme-result-dialog__panel>footer>strong { overflow:hidden; color:#e2e8f0; font-size:.72rem; text-overflow:ellipsis; white-space:nowrap; }
        .fme-result-dialog__panel>footer>div { display:flex; gap:.4rem; }
        .fme-result-dialog__panel>footer button { border-color:#9f1239; background:#9f1239; }
        .fme-copy-dialog { width:min(calc(100% - 2rem),30rem); max-width:30rem; padding:0; border:0; border-radius:1.1rem; color:var(--fme-ink); background:var(--fme-bg); box-shadow:0 25px 70px rgba(2,6,23,.35); }
        .fme-copy-dialog::backdrop { background:rgba(15,23,42,.65); backdrop-filter:blur(8px); }
        .fme-copy-dialog__panel { display:grid; gap:.85rem; padding:1.2rem; }
        .fme-copy-dialog__icon { display:grid; place-items:center; width:3rem; height:3rem; border-radius:.9rem; color:#be123c; background:#ffe4e6; }
        .dark .fme-copy-dialog__icon { color:#fda4af; background:#4c1d2b; }
        .fme-copy-dialog__icon svg { width:1.5rem; }
        .fme-copy-dialog__panel>div:nth-child(2) { display:grid; gap:.15rem; }
        .fme-copy-dialog__panel>div:nth-child(2)>span { color:#be123c; font-size:.62rem; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
        .fme-copy-dialog__panel h3 { font-size:1.05rem; font-weight:900; }
        .fme-copy-dialog__panel>div:nth-child(2)>p { color:var(--fme-muted); font-size:.7rem; }
        .fme-copy-dialog__panel label { display:grid; gap:.35rem; font-size:.7rem; font-weight:850; }
        .fme-copy-dialog__panel select { width:100%; min-height:2.8rem; padding:.55rem .65rem; border:1px solid var(--fme-line); border-radius:.7rem; color:var(--fme-ink); background:var(--fme-soft); font-size:.72rem; }
        .fme-copy-dialog__safe { display:flex; gap:.4rem; padding:.65rem; border-radius:.7rem; color:#166534; background:#f0fdf4; font-size:.65rem; font-weight:700; line-height:1.35; }
        .dark .fme-copy-dialog__safe { color:#bbf7d0; background:#10291c; }
        .fme-copy-dialog__safe svg { flex:0 0 auto; width:1rem; }
        .fme-copy-dialog__error { padding:.55rem .65rem; border-radius:.65rem; color:#b91c1c; background:#fef2f2; font-size:.68rem; font-weight:750; }
        .dark .fme-copy-dialog__error { color:#fecaca; background:#3b151b; }
        .fme-copy-dialog__panel>footer { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
        .fme-copy-dialog__panel>footer button { display:flex; align-items:center; justify-content:center; gap:.35rem; min-height:2.55rem; padding:.5rem; border-radius:.7rem; font-size:.72rem; font-weight:900; }
        .fme-copy-dialog__panel>footer button svg { width:1rem; }
        .fme-copy-dialog__panel>footer .is-cancel { border:1px solid var(--fme-line); color:var(--fme-ink); background:var(--fme-soft); }
        .fme-copy-dialog__panel>footer .is-copy { color:#fff; background:linear-gradient(135deg,#9f1239,#e11d48); }
        .fme-copy-dialog__panel>footer button:disabled { opacity:.55; cursor:wait; }
        [x-cloak] { display:none!important; }
        @keyframes fme-result-loading { from { background-position:100% 0; } to { background-position:-100% 0; } }
        @media (max-width:900px) { .fme-workspace { grid-template-columns:1fr; } .fme-editor-card { position:static; } }
        @media (max-width:640px) { .fme-hero { padding:1.05rem; } .fme-hero>svg { width:3rem; } .fme-heading { align-items:flex-start; flex-direction:column; } .fme-folder-grid { grid-template-columns:1fr; } .fme-photo-grid,.fme-result-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .fme-folder-bar { align-items:flex-start; flex-direction:column; } .fme-selection-bar { align-items:stretch; flex-direction:column; } .fme-selection-bar>div:last-child { display:grid; grid-template-columns:1fr 1fr; } .fme-selection-bar button.is-primary { grid-column:1/-1; justify-content:center; } .fme-result-actions { grid-template-columns:1fr; } .fme-result-dialog__nav { display:none; } .fme-result-dialog__panel>footer { align-items:stretch; flex-direction:column; } .fme-result-dialog__panel>footer>strong { max-width:100%; } .fme-result-dialog__panel>footer>div { display:grid; grid-template-columns:1fr 1fr; } }
    </style>
</x-filament-panels::page>
