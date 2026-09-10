const fotoBarangFolder = (config = {}) => ({
    photos: Array.isArray(config.photos) ? config.photos : [],
    sessionUuid: config.sessionUuid || '',
    archiveUrl: config.archiveUrl || '',
    selectedArchiveUrl: config.selectedArchiveUrl || '',
    totalPhotos: Number(config.totalPhotos || 0),
    focusPhotoId: Number(config.focusPhotoId || 0),
    highlightedPhotoId: null,
    selectionMode: false,
    selectedIds: [],
    longPressTimer: null,
    longPressTriggered: false,
    suppressClickUntil: 0,
    viewerOpen: false,
    viewerIndex: 0,
    viewerLoading: false,
    viewerError: false,
    touchStartX: null,
    confirmOpen: false,
    confirmBusy: false,
    confirmIds: [],
    confirmInput: '',
    actionMessage: '',
    downloadBusy: false,
    shareAllBusy: false,
    labelOpen: false,
    labelBusy: false,
    labelIds: [],
    labelItemId: '',
    labelHasExisting: false,
    sequentialTransactions: Array.isArray(config.sequentialTransactions) ? config.sequentialTransactions : [],
    unlabeledPhotoCount: Number(config.unlabeledPhotoCount || 0),
    sequentialOpen: false,
    sequentialBusy: false,
    sequentialExpenseId: '',

    initialize(nextConfig = {}) {
        this.photos = Array.isArray(nextConfig.photos) ? nextConfig.photos : [];
        this.sessionUuid = nextConfig.sessionUuid || '';
        this.archiveUrl = nextConfig.archiveUrl || '';
        this.selectedArchiveUrl = nextConfig.selectedArchiveUrl || '';
        this.totalPhotos = Number(nextConfig.totalPhotos || 0);
        this.focusPhotoId = Number(nextConfig.focusPhotoId || 0);
        this.sequentialTransactions = Array.isArray(nextConfig.sequentialTransactions) ? nextConfig.sequentialTransactions : [];
        this.unlabeledPhotoCount = Number(nextConfig.unlabeledPhotoCount || 0);
        this.$nextTick(() => {
            this.reconcileThumbnails();
            this.focusLinkedPhoto();
        });
    },

    thumbnailReady(image) {
        if (! image) return;
        image.classList.add('is-ready');
        image.parentElement?.classList.remove('is-failed');
        image.parentElement?.classList.add('is-ready');
    },

    thumbnailFailed(image) {
        if (! image) return;
        image.classList.remove('is-ready');
        image.parentElement?.classList.remove('is-ready');
        image.parentElement?.classList.add('is-failed');
    },

    settleThumbnail(image) {
        if (! image?.complete) return;
        if (image.naturalWidth > 0) this.thumbnailReady(image);
        else this.thumbnailFailed(image);
    },

    reconcileThumbnails() {
        this.$root?.querySelectorAll('.ff-card__image img').forEach((image) => {
            this.settleThumbnail(image);
        });
    },

    focusLinkedPhoto() {
        if (! Number.isInteger(this.focusPhotoId) || this.focusPhotoId < 1) return;

        const target = this.$root?.querySelector(`[data-photo-id="${this.focusPhotoId}"]`);
        if (! target) return;

        this.highlightedPhotoId = this.focusPhotoId;
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(() => {
            if (this.highlightedPhotoId === this.focusPhotoId) this.highlightedPhotoId = null;
        }, 2800);
    },

    destroy() {
        window.clearTimeout(this.longPressTimer);
        this.restoreScroll();
    },

    currentPhoto() {
        return this.photos[this.viewerIndex] || null;
    },

    isSelected(photoId) {
        return this.selectedIds.includes(Number(photoId));
    },

    beginSelection() {
        this.selectionMode = true;
        this.selectedIds = [];
    },

    clearSelection() {
        window.clearTimeout(this.longPressTimer);
        this.selectionMode = false;
        this.selectedIds = [];
        this.longPressTriggered = false;
    },

    requestLabel(ids, currentItemId = null) {
        const normalized = (Array.isArray(ids) ? ids : [ids])
            .map((id) => Number(id))
            .filter((id) => Number.isInteger(id) && id > 0 && this.photos.some((photo) => Number(photo.id) === id))
            .slice(0, 100);
        if (normalized.length === 0) return;

        const assignedIds = normalized
            .map((photoId) => this.photos.find((photo) => Number(photo.id) === photoId)?.purchaseItemId)
            .filter((itemId) => Number(itemId) > 0)
            .map(Number);
        const uniqueAssignedIds = [...new Set(assignedIds)];

        this.labelIds = normalized;
        this.labelItemId = Number(currentItemId) > 0
            ? String(Number(currentItemId))
            : (uniqueAssignedIds.length === 1 ? String(uniqueAssignedIds[0]) : '');
        this.labelHasExisting = assignedIds.length > 0;
        this.labelOpen = true;
        this.$nextTick(() => {
            const dialog = this.$refs.labelDialog;
            if (! dialog || dialog.open) return;
            try {
                dialog.showModal();
            } catch (error) {
                dialog.setAttribute('open', '');
            }
        });
    },

    closeLabelDialog() {
        if (this.labelBusy) return;
        this.labelOpen = false;
        this.labelIds = [];
        this.labelItemId = '';
        this.labelHasExisting = false;
        const dialog = this.$refs.labelDialog;
        if (dialog?.open && typeof dialog.close === 'function') dialog.close();
        else dialog?.removeAttribute('open');
        this.restoreScroll();
    },

    async saveLabel(remove = false) {
        if (this.labelBusy || this.labelIds.length === 0 || (! remove && this.labelItemId === '')) return;
        this.labelBusy = true;

        try {
            const result = await this.$wire.savePurchaseItemLabels(
                this.labelIds,
                remove ? null : Number(this.labelItemId),
            );
            if (! result?.saved) throw new Error(result?.message || 'Label barang gagal disimpan.');

            const affectedIds = new Set((result.photo_ids || this.labelIds).map(Number));
            const purchase = remove ? null : (result.purchase || null);
            this.photos = this.photos.map((photo) => affectedIds.has(Number(photo.id))
                ? {
                    ...photo,
                    purchaseItemId: remove ? null : Number(this.labelItemId),
                    purchase,
                }
                : photo);
            this.actionMessage = remove
                ? `${affectedIds.size} label barang berhasil dilepas.`
                : `${result.label || 'Barang'} ditetapkan ke ${affectedIds.size} foto.`;
            this.labelBusy = false;
            this.closeLabelDialog();
            this.clearSelection();
        } catch (error) {
            this.actionMessage = error?.message || 'Label barang gagal disimpan. Silakan coba kembali.';
            this.labelBusy = false;
        }
    },

    selectedSequentialTransaction() {
        const expenseId = Number(this.sequentialExpenseId);

        return this.sequentialTransactions.find((transaction) => Number(transaction.id) === expenseId) || null;
    },

    sequentialCountsMatch() {
        const transaction = this.selectedSequentialTransaction();

        return Boolean(transaction) && Number(transaction.item_count) === this.unlabeledPhotoCount;
    },

    requestSequentialLabel() {
        if (this.sequentialBusy || this.unlabeledPhotoCount < 1 || this.sequentialTransactions.length === 0) return;

        if (! this.sequentialExpenseId && this.sequentialTransactions.length === 1) {
            this.sequentialExpenseId = String(this.sequentialTransactions[0].id);
        }

        this.sequentialOpen = true;
        this.$nextTick(() => {
            const dialog = this.$refs.sequentialLabelDialog;
            if (! dialog || dialog.open) return;
            try {
                dialog.showModal();
            } catch (error) {
                dialog.setAttribute('open', '');
            }
        });
    },

    closeSequentialLabelDialog() {
        if (this.sequentialBusy) return;
        this.sequentialOpen = false;
        const dialog = this.$refs.sequentialLabelDialog;
        if (dialog?.open && typeof dialog.close === 'function') dialog.close();
        else dialog?.removeAttribute('open');
        this.restoreScroll();
    },

    async saveSequentialLabel() {
        if (this.sequentialBusy || ! this.sequentialCountsMatch()) return;
        this.sequentialBusy = true;

        try {
            const result = await this.$wire.saveSequentialPurchaseItemLabels(Number(this.sequentialExpenseId));
            if (! result?.saved) throw new Error(result?.message || 'Pelabelan berurutan gagal disimpan.');

            const assignments = new Map((result.assignments || []).map((assignment) => [
                Number(assignment.photo_id),
                assignment.purchase || null,
            ]));
            this.photos = this.photos.map((photo) => {
                const purchase = assignments.get(Number(photo.id));
                if (! purchase) return photo;

                return {
                    ...photo,
                    purchaseItemId: Number(purchase.id),
                    purchase,
                };
            });
            this.unlabeledPhotoCount = Number(result.unlabeled_photo_count || 0);
            this.actionMessage = `${assignments.size} foto diberi label mengikuti urutan transaksi.`;
            this.sequentialBusy = false;
            this.closeSequentialLabelDialog();
            this.clearSelection();
        } catch (error) {
            this.actionMessage = error?.message || 'Pelabelan berurutan gagal disimpan. Silakan coba kembali.';
            this.sequentialBusy = false;
        }
    },

    toggleSelection(photoId) {
        const id = Number(photoId);
        if (! this.photos.some((photo) => Number(photo.id) === id)) return;

        if (this.selectedIds.includes(id)) {
            this.selectedIds = this.selectedIds.filter((selectedId) => selectedId !== id);
        } else if (this.selectedIds.length < 100) {
            this.selectedIds = [...this.selectedIds, id];
        } else {
            this.actionMessage = 'Maksimal 100 foto dapat dipilih sekaligus.';
        }

        this.selectionMode = this.selectedIds.length > 0;
    },

    selectPage() {
        this.selectedIds = this.photos.slice(0, 100).map((photo) => Number(photo.id));
        this.selectionMode = this.selectedIds.length > 0;
    },

    startLongPress(photoId) {
        if (this.selectionMode) return;
        window.clearTimeout(this.longPressTimer);
        this.longPressTriggered = false;
        this.longPressTimer = window.setTimeout(() => {
            this.longPressTriggered = true;
            this.selectionMode = true;
            this.toggleSelection(photoId);
            navigator.vibrate?.(30);
        }, 520);
    },

    cancelLongPress() {
        window.clearTimeout(this.longPressTimer);
        this.longPressTimer = null;
        if (this.longPressTriggered) this.suppressClickUntil = Date.now() + 600;
    },

    handlePhotoClick(photoId, index) {
        if (Date.now() < this.suppressClickUntil) {
            this.longPressTriggered = false;
            return;
        }

        if (this.selectionMode) {
            this.toggleSelection(photoId);
            return;
        }

        this.openViewer(index);
    },

    openViewer(index = 0) {
        if (this.photos.length === 0) return;
        this.viewerIndex = Math.max(0, Math.min(Number(index), this.photos.length - 1));
        this.viewerLoading = true;
        this.viewerError = false;
        this.viewerOpen = true;
        this.$nextTick(() => {
            const dialog = this.$refs.viewerDialog;
            if (! dialog || dialog.open) return;
            try {
                dialog.showModal();
            } catch (error) {
                dialog.setAttribute('open', '');
            }
        });
    },

    closeViewer() {
        this.viewerOpen = false;
        this.touchStartX = null;
        const dialog = this.$refs.viewerDialog;
        if (dialog?.open && typeof dialog.close === 'function') dialog.close();
        else dialog?.removeAttribute('open');
        this.restoreScroll();
    },

    showPhoto(step) {
        if (this.photos.length < 2) return;
        this.viewerIndex = (this.viewerIndex + step + this.photos.length) % this.photos.length;
        this.viewerLoading = true;
        this.viewerError = false;
    },

    beginSwipe(event) {
        this.touchStartX = event.changedTouches?.[0]?.clientX ?? null;
    },

    endSwipe(event) {
        if (this.touchStartX === null) return;
        const endX = event.changedTouches?.[0]?.clientX ?? this.touchStartX;
        const distance = endX - this.touchStartX;
        this.touchStartX = null;
        if (Math.abs(distance) < 45) return;
        this.showPhoto(distance > 0 ? -1 : 1);
    },

    requestDelete(ids) {
        const normalized = (Array.isArray(ids) ? ids : [ids])
            .map((id) => Number(id))
            .filter((id) => Number.isInteger(id) && id > 0)
            .slice(0, 100);
        if (normalized.length === 0) return;

        this.confirmIds = normalized;
        this.confirmInput = '';
        this.confirmOpen = true;
        this.$nextTick(() => {
            const dialog = this.$refs.confirmDialog;
            if (! dialog || dialog.open) return;
            try {
                dialog.showModal();
            } catch (error) {
                dialog.setAttribute('open', '');
            }
            window.setTimeout(() => this.$refs.confirmInput?.focus(), 80);
        });
    },

    closeConfirm() {
        if (this.confirmBusy) return;
        this.confirmOpen = false;
        this.confirmIds = [];
        this.confirmInput = '';
        const dialog = this.$refs.confirmDialog;
        if (dialog?.open && typeof dialog.close === 'function') dialog.close();
        else dialog?.removeAttribute('open');
        this.restoreScroll();
    },

    async confirmDelete() {
        if (this.confirmBusy || this.confirmInput.trim().toLowerCase() !== 'hapus') return;
        this.confirmBusy = true;

        try {
            const result = await this.$wire.deleteSelectedPhotos(this.confirmIds, this.confirmInput);
            if (! result?.deleted) throw new Error(result?.message || 'Foto gagal dihapus.');

            const deletedIds = new Set((result.photo_ids || this.confirmIds).map(Number));
            this.photos = this.photos.filter((photo) => ! deletedIds.has(Number(photo.id)));
            this.totalPhotos = Math.max(0, this.totalPhotos - deletedIds.size);
            this.closeViewer();
            this.clearSelection();
            this.actionMessage = `${deletedIds.size} foto berhasil dihapus.`;
            this.confirmBusy = false;
            this.closeConfirm();
            await this.$wire.$refresh();
        } catch (error) {
            this.actionMessage = error?.message || 'Foto gagal dihapus. Silakan coba kembali.';
            this.confirmBusy = false;
        }
    },

    downloadSelected() {
        if (this.selectedIds.length === 0 || this.downloadBusy) return;
        this.downloadBusy = true;
        const separator = this.selectedArchiveUrl.includes('?') ? '&' : '?';
        window.location.href = `${this.selectedArchiveUrl}${separator}photos=${encodeURIComponent(this.selectedIds.join(','))}`;
        window.setTimeout(() => this.downloadBusy = false, 1800);
    },

    async shareArchive() {
        try {
            const response = await fetch(this.archiveUrl, { credentials: 'same-origin' });
            if (! response.ok) throw new Error('Arsip folder tidak dapat disiapkan.');
            const blob = await response.blob();
            const file = new File([blob], `foto-maps-${this.sessionUuid}.zip`, {
                type: blob.type || 'application/zip',
            });

            if (navigator.share && navigator.canShare?.({ files: [file] })) {
                await navigator.share({
                    title: 'Foto Maps Barang Datang',
                    text: `Laporan ${this.totalPhotos} foto barang datang`,
                    files: [file],
                });
                this.actionMessage = 'Arsip seluruh foto siap dibagikan ke WhatsApp.';
                return true;
            }

            window.location.href = this.archiveUrl;
            this.actionMessage = 'Perangkat tidak mendukung berbagi arsip; ZIP sedang diunduh.';
            return false;
        } catch (error) {
            if (error?.name === 'AbortError') throw error;
            window.location.href = this.archiveUrl;
            this.actionMessage = 'Berbagi langsung tidak tersedia; ZIP sedang diunduh.';
            return false;
        }
    },

    async shareAll() {
        if (! this.archiveUrl || this.shareAllBusy || this.totalPhotos < 1) return;
        this.shareAllBusy = true;
        this.actionMessage = 'Menyiapkan seluruh foto...';

        try {
            const manifest = await this.$wire.shareManifest();
            const photos = Array.isArray(manifest?.photos) ? manifest.photos : [];

            if (
                manifest?.mode !== 'direct'
                || photos.length === 0
                || ! navigator.share
                || ! navigator.canShare
            ) {
                await this.shareArchive();
                return;
            }

            const files = [];
            for (let index = 0; index < photos.length; index++) {
                const photo = photos[index];
                this.actionMessage = `Menyiapkan foto ${index + 1} dari ${photos.length}...`;
                const response = await fetch(photo.preview, { credentials: 'same-origin' });
                if (! response.ok) throw new Error('Salah satu foto tidak dapat dibaca.');
                const blob = await response.blob();
                files.push(new File([blob], photo.fileName, { type: blob.type || 'image/jpeg' }));
            }

            if (! navigator.canShare({ files })) {
                await this.shareArchive();
                return;
            }

            await navigator.share({
                title: 'Foto Maps Barang Datang',
                text: `Laporan ${files.length} foto barang datang`,
                files,
            });
            this.actionMessage = `${files.length} foto siap dibagikan ke WhatsApp.`;
        } catch (error) {
            if (error?.name === 'AbortError') {
                this.actionMessage = 'Berbagi dibatalkan.';
            } else {
                this.actionMessage = 'Berbagi foto langsung gagal, menyiapkan ZIP...';
                await this.shareArchive();
            }
        } finally {
            this.shareAllBusy = false;
        }
    },

    async sharePhoto(photo) {
        if (! photo) return;
        try {
            const response = await fetch(photo.preview, { credentials: 'same-origin' });
            if (! response.ok) throw new Error('Foto tidak dapat dibaca.');
            const blob = await response.blob();
            const file = new File([blob], photo.fileName, { type: blob.type || 'image/jpeg' });
            if (navigator.share && navigator.canShare?.({ files: [file] })) {
                await navigator.share({ title: 'Foto barang datang', files: [file] });
                return;
            }
        } catch (error) {
            if (error?.name === 'AbortError') return;
        }
        window.location.href = photo.download;
    },

    restoreScroll() {
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
        document.body.classList.remove('overflow-hidden');
    },
});

window.fotoBarangFolder = fotoBarangFolder;
