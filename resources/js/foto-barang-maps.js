const fotoBarangMaps = (config = {}) => ({
            gpsState: 'Mencari lokasi GPS...',
            gpsReady: false,
            locating: false,
            resolvingAddress: false,
            templateApplying: false,
            locationMode: 'gps',
            gpsRequestId: 0,
            latitude: config.latitude ?? null,
            longitude: config.longitude ?? null,
            accuracy: config.accuracy ?? null,
            cameraOpen: false,
            cameraStream: null,
            cameraVideoTrack: null,
            cameraReady: false,
            cameraError: '',
            torchSupported: false,
            torchEnabled: false,
            torchBusy: false,
            captureBusy: false,
            captureMode: 'server',
            verticalCropRatio: Number(config.verticalCropRatio ?? 0.045),
            sessionLocation: config.sessionLocation || '',
            sessionAddress: config.sessionAddress || '',
            uploadInProgress: false,
            uploadProgress: 0,
            queuedCount: 0,
            capturedCount: Number(config.capturedCount || 0),
            serverCapturedCount: Number(config.serverCapturedCount || 0),
            sessionUuid: config.sessionUuid || null,
            sessionIsActive: Boolean(config.sessionIsActive),
            uploadUrlTemplate: config.uploadUrlTemplate || '',
            selectedBarangId: Number(config.selectedBarangId || 0) || null,
            captureQueue: [],
            localCaptures: [],
            localCapturedCount: 0,
            localGalleryFor: null,
            localPreviewUrl: null,
            localPreviewCapture: null,
            serverPhotos: [],
            serverGalleryOpen: false,
            serverPhotoIndex: 0,
            serverImageLoading: false,
            serverImageError: false,
            galleryTouchStartX: null,
            serverSelectionMode: false,
            selectedServerPhotoIds: [],
            serverPhotoLongPressTimer: null,
            serverPhotoLongPressTriggered: false,
            suppressServerPhotoClickUntil: 0,
            selectedDownloadBusy: false,
            selectedArchiveUrlTemplate: config.selectedArchiveUrlTemplate || '',
            confirmOpen: false,
            confirmType: null,
            confirmTargetId: null,
            confirmTargetUuid: null,
            confirmTitle: '',
            confirmMessage: '',
            confirmInput: '',
            confirmRequiresText: false,
            confirmBusy: false,
            refreshTimer: null,
            serverRefreshPending: false,
            refreshInProgress: false,
            historyFiltersOpen: false,
            currentUploadId: null,
            captureDbPromise: null,
            queueInitializedFor: null,
            queueRetryTimer: null,
            backgroundState: '',
            finishRequested: false,
            finishAllowsEmptyLocal: false,
            finishingSession: false,
            liveTime: '',
            liveDate: '',
            liveDay: '',
            clockTimer: null,
            shutterAudioContext: null,
            beepEnabled: true,
            recoveryAvailable: false,
            recoveryMessage: '',
            shareAllBusy: false,
            shareAllProgress: 0,
            shareAllStatus: '',
            initializeCamera(nextConfig = {}) {
                this.latitude = nextConfig.latitude ?? null;
                this.longitude = nextConfig.longitude ?? null;
                this.accuracy = nextConfig.accuracy ?? null;
                this.verticalCropRatio = Number(nextConfig.verticalCropRatio ?? 0.045);
                this.sessionLocation = nextConfig.sessionLocation || '';
                this.sessionAddress = nextConfig.sessionAddress || '';
                this.capturedCount = Number(nextConfig.capturedCount || 0);
                this.serverCapturedCount = Number(nextConfig.serverCapturedCount || 0);
                this.sessionUuid = nextConfig.sessionUuid || null;
                this.sessionIsActive = Boolean(nextConfig.sessionIsActive);
                this.uploadUrlTemplate = nextConfig.uploadUrlTemplate || '';
                this.selectedBarangId = Number(nextConfig.selectedBarangId || 0) || null;
                this.selectedArchiveUrlTemplate = nextConfig.selectedArchiveUrlTemplate || '';
            },
            async initCamera() {
                this.gpsState = 'GPS akan diambil saat kamera dibuka';
                this.gpsReady = this.latitude !== null && this.longitude !== null;
                const recovery = this.loadDeviceSettings();
                this.updateClock();
                await Promise.allSettled([
                    this.initializeCaptureQueue(),
                    this.loadLocalGallery(),
                ]);
                this.syncServerPhotosFromDom();

                if (
                    this.sessionIsActive
                    && this.sessionUuid
                    && (recovery?.cameraWasOpen || this.queuedCount > 0)
                ) {
                    this.recoveryAvailable = true;
                    this.recoveryMessage = this.queuedCount > 0
                        ? `${this.queuedCount} foto antrean dipulihkan dan dikirim otomatis.`
                        : `Sesi ${this.captureMode === 'local' ? 'Lokal HP' : 'Server'} sebelumnya siap dilanjutkan.`;
                }
            },
            destroy() {
                window.clearTimeout(this.refreshTimer);
                window.clearTimeout(this.queueRetryTimer);
                window.clearTimeout(this.serverPhotoLongPressTimer);
                this.serverRefreshPending = false;
                this.serverGalleryOpen = false;
                this.confirmOpen = false;
                this.closeCamera();
                this.closeLocalPreview();
                if (this.shutterAudioContext) {
                    this.shutterAudioContext.close().catch(() => {});
                    this.shutterAudioContext = null;
                }
                document.body.style.overflow = '';
            },
            loadDeviceSettings() {
                let recovery = null;

                try {
                    this.beepEnabled = window.localStorage.getItem('handayani-foto-maps-beep') !== 'off';
                    const storedRecovery = window.localStorage.getItem('handayani-foto-maps-recovery');
                    recovery = storedRecovery ? JSON.parse(storedRecovery) : null;

                    if (recovery?.sessionUuid === this.sessionUuid) {
                        this.captureMode = ['server', 'local'].includes(recovery.captureMode)
                            ? recovery.captureMode
                            : 'server';
                    } else {
                        recovery = null;
                    }
                } catch (error) {
                    this.beepEnabled = true;
                    recovery = null;
                }

                return recovery;
            },
            persistSessionRecovery(cameraWasOpen = this.cameraOpen) {
                if (! this.sessionUuid) return;

                try {
                    window.localStorage.setItem('handayani-foto-maps-recovery', JSON.stringify({
                        sessionUuid: this.sessionUuid,
                        captureMode: this.captureMode,
                        cameraWasOpen: Boolean(cameraWasOpen),
                        updatedAt: Date.now(),
                    }));
                } catch (error) {
                    // IndexedDB tetap menjadi penyimpanan utama foto bila localStorage tidak tersedia.
                }
            },
            clearSessionRecovery() {
                try {
                    const storedRecovery = window.localStorage.getItem('handayani-foto-maps-recovery');
                    const recovery = storedRecovery ? JSON.parse(storedRecovery) : null;
                    if (! recovery || recovery.sessionUuid === this.sessionUuid) {
                        window.localStorage.removeItem('handayani-foto-maps-recovery');
                    }
                } catch (error) {
                    try {
                        window.localStorage.removeItem('handayani-foto-maps-recovery');
                    } catch (storageError) {
                        // Pemulihan IndexedDB tetap berjalan tanpa localStorage.
                    }
                }

                this.recoveryAvailable = false;
                this.recoveryMessage = '';
            },
            setCaptureMode(mode) {
                if (! ['server', 'local'].includes(mode) || this.captureBusy) return;
                this.captureMode = mode;
                this.persistSessionRecovery(this.cameraOpen);
            },
            dismissRecovery() {
                this.recoveryAvailable = false;
                this.persistSessionRecovery(false);
            },
            async resumeRecoveredSession() {
                this.recoveryAvailable = false;
                await this.openCamera();
            },
            toggleShutterBeep() {
                this.beepEnabled = ! this.beepEnabled;

                try {
                    window.localStorage.setItem(
                        'handayani-foto-maps-beep',
                        this.beepEnabled ? 'on' : 'off',
                    );
                } catch (error) {
                    // Preferensi tetap aktif selama halaman ini terbuka.
                }

                if (this.beepEnabled) this.playShutterBeep();
            },
            updateClock() {
                const now = new Date();
                const timeZone = 'Asia/Jakarta';
                this.liveTime = new Intl.DateTimeFormat('id-ID', {
                    timeZone, hour: '2-digit', minute: '2-digit', hour12: false,
                }).format(now).replace('.', ':');
                this.liveDate = new Intl.DateTimeFormat('id-ID', {
                    timeZone, day: '2-digit', month: 'short', year: 'numeric',
                }).format(now);
                this.liveDay = new Intl.DateTimeFormat('id-ID', {
                    timeZone, weekday: 'long',
                }).format(now);
            },
            playShutterBeep() {
                if (! this.beepEnabled) return;

                try {
                    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                    if (! AudioContextClass) return;

                    if (! this.shutterAudioContext || this.shutterAudioContext.state === 'closed') {
                        this.shutterAudioContext = new AudioContextClass();
                    }

                    const context = this.shutterAudioContext;
                    const emitBeep = () => {
                        if (context.state !== 'running') return;

                        const startedAt = context.currentTime;
                        const oscillator = context.createOscillator();
                        const gain = context.createGain();
                        oscillator.type = 'square';
                        oscillator.frequency.setValueAtTime(1280, startedAt);
                        gain.gain.setValueAtTime(0.0001, startedAt);
                        gain.gain.exponentialRampToValueAtTime(0.3, startedAt + 0.006);
                        gain.gain.exponentialRampToValueAtTime(0.0001, startedAt + 0.135);
                        oscillator.connect(gain);
                        gain.connect(context.destination);
                        oscillator.onended = () => {
                            oscillator.disconnect();
                            gain.disconnect();
                        };
                        oscillator.start(startedAt);
                        oscillator.stop(startedAt + 0.14);
                    };

                    if (context.state === 'suspended') {
                        context.resume().then(emitBeep).catch(() => {});
                    } else {
                        emitBeep();
                    }
                } catch (error) {
                    // Audio hanya umpan balik tambahan; kegagalannya tidak boleh mengganggu potret.
                }
            },
            openCaptureDb() {
                if (this.captureDbPromise) return this.captureDbPromise;

                this.captureDbPromise = new Promise((resolve, reject) => {
                    if (! window.indexedDB) {
                        reject(new Error('Penyimpanan aman perangkat tidak didukung browser ini.'));
                        return;
                    }

                    const request = window.indexedDB.open('handayani-foto-maps', 2);
                    request.onupgradeneeded = () => {
                        const database = request.result;
                        let store;
                        if (! database.objectStoreNames.contains('captures')) {
                            store = database.createObjectStore('captures', { keyPath: 'id' });
                            store.createIndex('sessionUuid', 'sessionUuid', { unique: false });
                        } else {
                            store = request.transaction.objectStore('captures');
                        }

                        if (! store.indexNames.contains('mode')) {
                            store.createIndex('mode', 'mode', { unique: false });
                        }
                    };
                    request.onsuccess = () => {
                        const database = request.result;
                        database.onversionchange = () => {
                            database.close();
                            this.captureDbPromise = null;
                        };
                        resolve(database);
                    };
                    request.onerror = () => {
                        this.captureDbPromise = null;
                        reject(request.error || new Error('Penyimpanan perangkat gagal dibuka.'));
                    };
                });

                return this.captureDbPromise;
            },
            async saveLocalCapture(capture) {
                const database = await this.openCaptureDb();
                await new Promise((resolve, reject) => {
                    let transaction;

                    try {
                        transaction = database.transaction('captures', 'readwrite', { durability: 'strict' });
                    } catch (error) {
                        transaction = database.transaction('captures', 'readwrite');
                    }

                    transaction.objectStore('captures').put(capture);
                    transaction.oncomplete = () => resolve();
                    transaction.onerror = () => reject(transaction.error || new Error('Foto gagal diamankan di perangkat.'));
                    transaction.onabort = () => reject(transaction.error || new Error('Penyimpanan foto dibatalkan perangkat.'));
                });
            },
            async saveLocalCaptureWithRetry(capture) {
                let lastError = null;

                for (let attempt = 0; attempt < 2; attempt++) {
                    try {
                        await this.saveLocalCapture(capture);
                        return;
                    } catch (error) {
                        lastError = error;
                        this.captureDbPromise = null;

                        if (attempt === 0) {
                            await new Promise((resolve) => window.setTimeout(resolve, 60));
                        }
                    }
                }

                throw lastError || new Error('Foto gagal diamankan di perangkat.');
            },
            async deleteLocalCapture(captureId) {
                const database = await this.openCaptureDb();
                await new Promise((resolve, reject) => {
                    const transaction = database.transaction('captures', 'readwrite');
                    transaction.objectStore('captures').delete(captureId);
                    transaction.oncomplete = () => resolve();
                    transaction.onerror = () => reject(transaction.error || new Error('Antrean lokal gagal dibersihkan.'));
                });
            },
            async readLocalCaptures(sessionUuid, mode = 'server') {
                if (! sessionUuid) return [];
                const database = await this.openCaptureDb();

                return await new Promise((resolve, reject) => {
                    const transaction = database.transaction('captures', 'readonly');
                    const captures = [];
                    const request = transaction.objectStore('captures').index('sessionUuid').openCursor(
                        IDBKeyRange.only(sessionUuid),
                    );
                    request.onsuccess = () => {
                        const cursor = request.result;
                        if (! cursor) {
                            resolve(captures.sort((first, second) => first.createdAt - second.createdAt));
                            return;
                        }

                        const storedCapture = cursor.value;
                        const storedMode = storedCapture.mode || 'server';
                        if (storedMode === mode) {
                            const { blob, ...metadata } = storedCapture;
                            captures.push({
                                ...metadata,
                                fileSize: Number(metadata.fileSize || blob?.size || 0),
                            });
                        }
                        cursor.continue();
                    };
                    request.onerror = () => reject(request.error || new Error('Antrean foto tidak dapat dibaca.'));
                });
            },
            async getLocalCapture(captureId) {
                const database = await this.openCaptureDb();

                return await new Promise((resolve, reject) => {
                    const transaction = database.transaction('captures', 'readonly');
                    const request = transaction.objectStore('captures').get(captureId);
                    request.onsuccess = () => resolve(request.result || null);
                    request.onerror = () => reject(request.error || new Error('Foto lokal tidak dapat dibaca.'));
                });
            },
            async initializeCaptureQueue(sessionUuid = this.sessionUuid) {
                if (! sessionUuid) return;
                if (this.queueInitializedFor === sessionUuid) return;

                this.queueInitializedFor = sessionUuid;
                this.sessionUuid = sessionUuid;

                navigator.storage?.persist?.().catch(() => false);

                try {
                    const storedCaptures = await this.readLocalCaptures(sessionUuid, 'server');
                    const knownIds = new Set(this.captureQueue.map((capture) => capture.id));
                    this.captureQueue.push(...storedCaptures.filter((capture) => ! knownIds.has(capture.id)));
                    this.captureQueue.sort((first, second) => first.createdAt - second.createdAt);
                    this.queuedCount = this.captureQueue.length;
                    this.capturedCount = Math.max(
                        this.capturedCount,
                        this.serverCapturedCount + this.queuedCount,
                    );

                    if (this.queuedCount > 0) {
                        this.backgroundState = `${this.queuedCount} foto aman di perangkat, menunggu dikirim`;
                        this.processUploadQueue();
                    }
                } catch (error) {
                    this.queueInitializedFor = null;
                    this.backgroundState = error?.message || 'Antrean lokal tidak dapat dipulihkan.';
                }
            },
            async loadLocalGallery(sessionUuid = this.sessionUuid) {
                if (! sessionUuid) {
                    this.localCaptures = [];
                    this.localCapturedCount = 0;
                    return;
                }

                try {
                    this.localCaptures = (await this.readLocalCaptures(sessionUuid, 'local')).reverse();
                    this.localCapturedCount = this.localCaptures.length;
                    this.localGalleryFor = sessionUuid;
                } catch (error) {
                    this.backgroundState = error?.message || 'Foto lokal tidak dapat dibaca.';
                }
            },
            wrapCanvasText(context, text, maxWidth, maxLines = 2) {
                const words = String(text || '-').trim().split(/\s+/);
                const lines = [];
                let currentLine = '';

                for (const word of words) {
                    const candidate = currentLine ? `${currentLine} ${word}` : word;
                    if (currentLine && context.measureText(candidate).width > maxWidth) {
                        lines.push(currentLine);
                        currentLine = word;
                        if (lines.length === maxLines - 1) break;
                    } else {
                        currentLine = candidate;
                    }
                }

                if (currentLine && lines.length < maxLines) lines.push(currentLine);
                if (lines.join(' ').length < String(text || '').trim().length && lines.length) {
                    let lastLine = lines[lines.length - 1];
                    while (lastLine.length > 1 && context.measureText(`${lastLine}...`).width > maxWidth) {
                        lastLine = lastLine.slice(0, -1);
                    }
                    lines[lines.length - 1] = `${lastLine.trim()}...`;
                }

                return lines;
            },
            drawLocalWatermark(canvas, context, capturedAt) {
                const width = canvas.width;
                const height = canvas.height;
                const base = Math.min(width, height * 0.82);
                const padding = Math.max(18, base * 0.025);
                const contentX = padding * 1.05;
                const timeFont = Math.max(45, base * 0.082);
                const dateFont = Math.max(26, base * 0.043);
                const locationFont = Math.max(23, base * 0.035);
                const addressFont = Math.max(18, base * 0.0245);
                const coordinateFont = Math.max(16, base * 0.021);
                context.font = `600 ${addressFont}px 'Roboto Condensed', 'Arial Narrow', Arial, sans-serif`;
                const addressLines = this.wrapCanvasText(context, this.sessionAddress, width - (contentX * 2), 4);
                const timeRowHeight = timeFont * 1.12;
                const locationLineHeight = locationFont * 1.18;
                const addressLineHeight = addressFont * 1.14;
                const outerBottomSpace = padding * 0.40;
                const innerBottomSpace = padding * 0.40;
                const overlayHeight = padding
                    + timeRowHeight
                    + (padding * 0.28)
                    + locationLineHeight
                    + (addressLines.length * addressLineHeight)
                    + (padding * 0.12)
                    + coordinateFont
                    + innerBottomSpace;
                const overlayBottom = height - outerBottomSpace;
                const overlayTop = overlayBottom - overlayHeight;
                const timeZone = 'Asia/Jakarta';
                const date = new Date(capturedAt);
                const time = new Intl.DateTimeFormat('en-US', {
                    timeZone, hour: '2-digit', minute: '2-digit', hour12: true,
                }).format(date);
                const dateText = new Intl.DateTimeFormat('id-ID', {
                    timeZone, day: '2-digit', month: 'short', year: 'numeric',
                }).format(date);
                const dayText = new Intl.DateTimeFormat('id-ID', {
                    timeZone, weekday: 'long',
                }).format(date);

                context.save();
                context.fillStyle = 'rgba(2, 7, 13, .78)';
                context.fillRect(padding * 0.45, overlayTop, width - (padding * 0.9), overlayHeight);

                const badgeFont = Math.max(14, base * 0.0185);
                const badgeText = 'HANDAYANI MAP CAMERA';
                context.font = `800 ${badgeFont}px 'Roboto Condensed', 'Arial Narrow', Arial, sans-serif`;
                const badgeWidth = context.measureText(badgeText).width + (padding * 1.6);
                const badgeHeight = badgeFont * 2.05;
                const badgeX = width - padding - badgeWidth;
                const badgeY = overlayTop - badgeHeight - (padding * 0.25);
                context.fillStyle = 'rgba(2, 7, 13, .76)';
                context.fillRect(badgeX, badgeY, badgeWidth, badgeHeight);
                context.fillStyle = '#fbbf24';
                context.beginPath();
                context.arc(badgeX + padding * 0.55, badgeY + badgeHeight / 2, badgeFont * 0.28, 0, Math.PI * 2);
                context.fill();
                context.fillStyle = '#ffffff';
                context.textBaseline = 'middle';
                context.fillText(badgeText, badgeX + padding, badgeY + badgeHeight / 2);

                let y = overlayTop + padding;
                context.textBaseline = 'top';
                context.fillStyle = '#ffffff';
                context.font = `800 ${timeFont}px 'Roboto Condensed', 'Arial Narrow', Arial, sans-serif`;
                context.fillText(time, contentX, y);
                const timeWidth = context.measureText(time).width;
                const dividerX = contentX + timeWidth + padding * 0.75;
                const dividerHeight = timeRowHeight;
                context.fillStyle = '#f7b500';
                context.fillRect(dividerX, y, Math.max(4, base * 0.004), dividerHeight);

                const dateX = dividerX + padding * 0.55;
                context.fillStyle = '#ffffff';
                context.font = `800 ${dateFont}px 'Roboto Condensed', 'Arial Narrow', Arial, sans-serif`;
                context.fillText(dateText, dateX, y);
                context.fillText(dayText, dateX, y + dateFont * 1.02);
                y += dividerHeight + padding * 0.28;

                context.font = `800 ${locationFont}px 'Roboto Condensed', 'Arial Narrow', Arial, sans-serif`;
                context.fillStyle = '#ffffff';
                const locationLines = this.wrapCanvasText(context, this.sessionLocation, width - (contentX * 2), 1);
                context.fillText(locationLines[0] || '-', contentX, y);
                const flagX = Math.min(width - contentX - locationFont * 1.45, contentX + context.measureText(locationLines[0] || '-').width + padding * .35);
                context.fillStyle = '#ef4444';
                context.fillRect(flagX, y + locationFont * .12, locationFont * 1.25, locationFont * .38);
                context.fillStyle = '#ffffff';
                context.fillRect(flagX, y + locationFont * .5, locationFont * 1.25, locationFont * .38);
                y += locationLineHeight;

                context.font = `600 ${addressFont}px 'Roboto Condensed', 'Arial Narrow', Arial, sans-serif`;
                context.fillStyle = '#f3f5f8';
                for (const line of addressLines) {
                    context.fillText(line, contentX, y);
                    y += addressLineHeight;
                }

                context.font = `600 ${coordinateFont}px 'Roboto Condensed', 'Arial Narrow', Arial, sans-serif`;
                context.fillStyle = '#e6ebf1';
                context.fillText(
                    `Lat ${Number(this.latitude).toFixed(6)} | Long ${Number(this.longitude).toFixed(6)}`,
                    contentX,
                    Math.min(y + padding * .12, overlayBottom - innerBottomSpace - coordinateFont),
                );
                context.restore();
            },
            localCaptureDate(capture) {
                return new Intl.DateTimeFormat('id-ID', {
                    timeZone: 'Asia/Jakarta', day: '2-digit', month: 'short', year: 'numeric',
                    hour: '2-digit', minute: '2-digit',
                }).format(new Date(capture.capturedAt));
            },
            localCaptureFileName(capture) {
                const sequence = String(capture.localSequence || 1).padStart(2, '0');
                return `${sequence}_foto_maps_handayani_${capture.id.slice(0, 8)}.jpg`;
            },
            nextLocalSequence() {
                return this.localCaptures.reduce(
                    (highest, capture) => Math.max(highest, Number(capture.localSequence || 0)),
                    0,
                ) + 1;
            },
            async downloadLocalCapture(captureId) {
                const capture = await this.getLocalCapture(captureId);
                if (! capture?.blob) {
                    this.backgroundState = 'File foto lokal tidak ditemukan.';
                    return;
                }
                const url = URL.createObjectURL(capture.blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = this.localCaptureFileName(capture);
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 1500);
            },
            async shareLocalCapture(captureId) {
                const capture = await this.getLocalCapture(captureId);
                if (! capture?.blob) return;
                const file = new File([capture.blob], this.localCaptureFileName(capture), {
                    type: capture.blob.type || 'image/jpeg', lastModified: capture.createdAt,
                });
                try {
                    if (navigator.share && navigator.canShare?.({ files: [file] })) {
                        await navigator.share({
                            title: 'Foto barang datang',
                            text: 'Laporan foto barang datang Logistik Handayani',
                            files: [file],
                        });
                        return;
                    }
                } catch (error) {
                    if (error?.name === 'AbortError') return;
                }
                await this.downloadLocalCapture(captureId);
            },
            async previewLocalCapture(captureId) {
                const capture = await this.getLocalCapture(captureId);
                if (! capture?.blob) return;
                this.closeLocalPreview();
                this.localPreviewCapture = capture;
                this.localPreviewUrl = URL.createObjectURL(capture.blob);
            },
            closeLocalPreview() {
                if (this.localPreviewUrl) URL.revokeObjectURL(this.localPreviewUrl);
                this.localPreviewUrl = null;
                this.localPreviewCapture = null;
                if (this.serverRefreshPending) this.scheduleServerRefresh(100);
            },
            async deleteLocalOnlyCapture(captureId) {
                await this.deleteLocalCapture(captureId);
                this.localCaptures = this.localCaptures.filter((capture) => capture.id !== captureId);
                this.localCapturedCount = this.localCaptures.length;
                if (this.localPreviewCapture?.id === captureId) this.closeLocalPreview();
            },
            syncServerPhotos(photos) {
                this.serverPhotos = Array.isArray(photos) ? photos : [];
                const availableIds = new Set(this.serverPhotos.map((photo) => Number(photo.id)));
                this.selectedServerPhotoIds = this.selectedServerPhotoIds.filter((id) => availableIds.has(id));
                this.serverSelectionMode = this.selectedServerPhotoIds.length > 0;
                if (this.serverPhotos.length === 0) this.closeServerGallery();
                if (this.serverPhotoIndex >= this.serverPhotos.length) {
                    this.serverPhotoIndex = Math.max(0, this.serverPhotos.length - 1);
                }
            },
            syncServerPhotosFromDom() {
                const source = this.$root.querySelector('[data-server-photos]');
                if (! source) {
                    this.syncServerPhotos([]);
                    return;
                }

                try {
                    this.syncServerPhotos(JSON.parse(source.textContent || '[]'));
                } catch (error) {
                    this.syncServerPhotos([]);
                    this.backgroundState = 'Daftar foto perlu dimuat ulang.';
                }
            },
            isServerPhotoSelected(photoId) {
                return this.selectedServerPhotoIds.includes(Number(photoId));
            },
            toggleServerPhotoSelection(photoId) {
                const id = Number(photoId);
                if (! Number.isInteger(id) || id < 1 || ! this.serverPhotos.some((photo) => Number(photo.id) === id)) return;
                if (this.selectedServerPhotoIds.includes(id)) {
                    this.selectedServerPhotoIds = this.selectedServerPhotoIds.filter((selectedId) => selectedId !== id);
                } else if (this.selectedServerPhotoIds.length < 100) {
                    this.selectedServerPhotoIds = [...this.selectedServerPhotoIds, id];
                } else {
                    this.backgroundState = 'Maksimal 100 foto dapat dipilih sekaligus.';
                }
                this.serverSelectionMode = this.selectedServerPhotoIds.length > 0;
                if (! this.serverSelectionMode && this.serverRefreshPending) this.scheduleServerRefresh(100);
            },
            startServerPhotoLongPress(photoId) {
                if (this.serverSelectionMode) return;
                window.clearTimeout(this.serverPhotoLongPressTimer);
                this.serverPhotoLongPressTriggered = false;
                this.serverPhotoLongPressTimer = window.setTimeout(() => {
                    this.serverPhotoLongPressTriggered = true;
                    this.serverSelectionMode = true;
                    this.toggleServerPhotoSelection(photoId);
                    navigator.vibrate?.(30);
                }, 520);
            },
            cancelServerPhotoLongPress() {
                window.clearTimeout(this.serverPhotoLongPressTimer);
                this.serverPhotoLongPressTimer = null;
                if (this.serverPhotoLongPressTriggered) {
                    this.suppressServerPhotoClickUntil = Date.now() + 600;
                }
            },
            handleServerPhotoClick(photoId, index) {
                if (Date.now() < this.suppressServerPhotoClickUntil) {
                    this.serverPhotoLongPressTriggered = false;
                    return;
                }
                if (this.serverSelectionMode) {
                    this.toggleServerPhotoSelection(photoId);
                    return;
                }
                this.openServerGallery(index);
            },
            selectAllServerPhotos() {
                this.selectedServerPhotoIds = this.serverPhotos
                    .map((photo) => Number(photo.id))
                    .filter((id) => Number.isInteger(id) && id > 0)
                    .slice(0, 100);
                this.serverSelectionMode = this.selectedServerPhotoIds.length > 0;
                if (this.serverPhotos.length > 100) {
                    this.backgroundState = '100 foto pertama dipilih. Batas maksimal aksi massal adalah 100 foto.';
                }
            },
            beginServerPhotoSelection() {
                this.serverSelectionMode = true;
                this.selectedServerPhotoIds = [];
            },
            clearServerPhotoSelection() {
                window.clearTimeout(this.serverPhotoLongPressTimer);
                this.serverPhotoLongPressTimer = null;
                this.serverPhotoLongPressTriggered = false;
                this.serverSelectionMode = false;
                this.selectedServerPhotoIds = [];
                if (this.serverRefreshPending) this.scheduleServerRefresh(100);
            },
            requestDeleteSelectedServerPhotos() {
                if (this.selectedServerPhotoIds.length < 1) return;
                this.openConfirm({
                    type: 'server-photos',
                    targetId: this.selectedServerPhotoIds.join(','),
                    title: `Hapus ${this.selectedServerPhotoIds.length} foto terpilih?`,
                    message: 'Semua foto terpilih akan dihapus permanen dari folder dan server.',
                    requiresText: true,
                });
            },
            downloadSelectedServerPhotos() {
                if (! this.sessionUuid || this.selectedServerPhotoIds.length < 1 || this.selectedDownloadBusy) return;
                const ids = this.selectedServerPhotoIds
                    .map((id) => Number(id))
                    .filter((id) => Number.isInteger(id) && id > 0)
                    .slice(0, 100);
                if (ids.length < 1) return;
                this.selectedDownloadBusy = true;
                const url = this.selectedArchiveUrlTemplate.replace(
                    '__SESSION_UUID__',
                    encodeURIComponent(this.sessionUuid),
                ) + `?photos=${encodeURIComponent(ids.join(','))}`;
                const link = document.createElement('a');
                link.href = url;
                link.download = '';
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => this.selectedDownloadBusy = false, 1800);
            },
            openServerGallery(index = 0) {
                window.clearTimeout(this.refreshTimer);
                this.syncServerPhotosFromDom();
                if (this.serverPhotos.length === 0) return;
                this.serverPhotoIndex = Math.max(0, Math.min(Number(index), this.serverPhotos.length - 1));
                this.serverImageLoading = true;
                this.serverImageError = false;
                this.serverGalleryOpen = true;
                this.$nextTick(() => {
                    const dialog = this.$refs.serverGalleryDialog;
                    if (! dialog || dialog.open) return;

                    if (typeof dialog.showModal === 'function') {
                        try {
                            dialog.showModal();
                        } catch (error) {
                            dialog.setAttribute('open', '');
                        }
                    } else {
                        dialog.setAttribute('open', '');
                    }
                });
            },
            closeServerGallery() {
                this.serverGalleryOpen = false;
                this.galleryTouchStartX = null;
                const dialog = this.$refs.serverGalleryDialog;
                if (dialog?.open && typeof dialog.close === 'function') {
                    dialog.close();
                } else {
                    dialog?.removeAttribute('open');
                }
                if (! this.confirmOpen && this.serverRefreshPending) this.scheduleServerRefresh(100);
            },
            currentServerPhoto() {
                return this.serverPhotos[this.serverPhotoIndex] || null;
            },
            showPreviousServerPhoto() {
                if (this.serverPhotos.length < 2) return;
                this.serverPhotoIndex = (this.serverPhotoIndex - 1 + this.serverPhotos.length) % this.serverPhotos.length;
                this.serverImageLoading = true;
                this.serverImageError = false;
            },
            showNextServerPhoto() {
                if (this.serverPhotos.length < 2) return;
                this.serverPhotoIndex = (this.serverPhotoIndex + 1) % this.serverPhotos.length;
                this.serverImageLoading = true;
                this.serverImageError = false;
            },
            beginGallerySwipe(event) {
                this.galleryTouchStartX = event.changedTouches?.[0]?.clientX ?? null;
            },
            endGallerySwipe(event) {
                const endX = event.changedTouches?.[0]?.clientX;
                if (this.galleryTouchStartX === null || endX === undefined) return;
                const distance = endX - this.galleryTouchStartX;
                this.galleryTouchStartX = null;
                if (Math.abs(distance) < 45) return;
                distance > 0 ? this.showPreviousServerPhoto() : this.showNextServerPhoto();
            },
            requestDeleteServerPhoto(photoId) {
                if (this.serverGalleryOpen) this.closeServerGallery();
                this.openConfirm({
                    type: 'server-photo',
                    targetId: photoId,
                    title: 'Hapus foto ini?',
                    message: 'Foto akan dihapus permanen dari folder dan server.',
                });
            },
            requestDeleteLocalPhoto(captureId) {
                this.openConfirm({
                    type: 'local-photo',
                    targetId: captureId,
                    title: 'Hapus foto lokal?',
                    message: 'Foto akan dihapus permanen dari perangkat ini.',
                });
            },
            requestDeleteFolder(sessionId, sessionUuid, sessionTitle) {
                this.openConfirm({
                    type: 'folder',
                    targetId: sessionId,
                    targetUuid: sessionUuid,
                    title: 'Hapus seluruh folder?',
                    message: `Folder ${sessionTitle} dan seluruh fotonya akan dihapus permanen.`,
                    requiresText: true,
                });
            },
            openConfirm({ type, targetId, targetUuid = null, title, message, requiresText = false }) {
                if (targetId === null || targetId === undefined) return;
                window.clearTimeout(this.refreshTimer);
                const dialog = this.$refs.confirmDialog;
                if (! dialog) return;
                dialog.dataset.deleteType = String(type);
                dialog.dataset.deleteTargetId = String(targetId);
                dialog.dataset.deleteTargetUuid = targetUuid === null ? '' : String(targetUuid);
                this.confirmType = type;
                this.confirmTargetId = targetId;
                this.confirmTargetUuid = targetUuid;
                this.confirmTitle = title;
                this.confirmMessage = message;
                this.confirmRequiresText = requiresText;
                this.confirmInput = '';
                this.confirmOpen = true;
                this.$nextTick(() => {
                    if (dialog.open) return;

                    if (typeof dialog.showModal === 'function') {
                        try {
                            dialog.showModal();
                        } catch (error) {
                            dialog.setAttribute('open', '');
                        }
                    } else {
                        dialog.setAttribute('open', '');
                    }
                });
            },
            closeConfirm() {
                if (this.confirmBusy) return;
                this.confirmOpen = false;
                const dialog = this.$refs.confirmDialog;
                if (dialog?.open && typeof dialog.close === 'function') {
                    dialog.close();
                } else {
                    dialog?.removeAttribute('open');
                }
                this.confirmType = null;
                this.confirmTargetId = null;
                this.confirmTargetUuid = null;
                this.confirmInput = '';
                if (dialog) {
                    delete dialog.dataset.deleteType;
                    delete dialog.dataset.deleteTargetId;
                    delete dialog.dataset.deleteTargetUuid;
                }
                if (! this.cameraOpen && ! this.serverGalleryOpen) {
                    document.body.style.overflow = '';
                }
                if (this.serverRefreshPending) this.scheduleServerRefresh(100);
            },
            async executeConfirmedDelete() {
                if (this.confirmBusy) return;
                const dialog = this.$refs.confirmDialog;
                const deleteType = dialog?.dataset.deleteType || this.confirmType;
                const targetId = dialog?.dataset.deleteTargetId || this.confirmTargetId;
                const targetUuid = dialog?.dataset.deleteTargetUuid || this.confirmTargetUuid;
                const confirmation = String(this.$refs.confirmTextInput?.value ?? this.confirmInput).trim();
                if (['folder', 'server-photos'].includes(deleteType) && confirmation.toLowerCase() !== 'hapus') {
                    this.confirmMessage = deleteType === 'folder'
                        ? 'Ketik hapus dengan lengkap untuk menghapus seluruh folder.'
                        : 'Ketik hapus dengan lengkap untuk menghapus foto terpilih.';
                    return;
                }
                this.confirmBusy = true;
                let deleted = false;
                let refreshAfterDelete = false;

                try {
                    if (deleteType === 'server-photo') {
                        const photoId = Number(targetId);
                        if (! Number.isInteger(photoId) || photoId < 1) throw new Error('ID foto tidak valid.');
                        const result = await this.$wire.deletePhoto(photoId);
                        if (! result?.deleted) throw new Error('Server belum menghapus foto.');
                        this.serverPhotos = this.serverPhotos.filter((photo) => photo.id !== photoId);
                        if (this.serverPhotos.length === 0) this.closeServerGallery();
                        this.serverPhotoIndex = Math.min(this.serverPhotoIndex, Math.max(0, this.serverPhotos.length - 1));
                        refreshAfterDelete = true;
                    } else if (deleteType === 'server-photos') {
                        const photoIds = String(targetId || '')
                            .split(',')
                            .map((id) => Number(id))
                            .filter((id) => Number.isInteger(id) && id > 0)
                            .slice(0, 100);
                        if (photoIds.length < 1) throw new Error('Daftar foto terpilih tidak valid.');
                        const result = await this.$wire.deleteSelectedPhotos(photoIds, confirmation);
                        if (! result?.deleted) throw new Error(result?.message || 'Server belum menghapus foto terpilih.');
                        const deletedIds = new Set((result.photo_ids || photoIds).map((id) => Number(id)));
                        this.serverPhotos = this.serverPhotos.filter((photo) => ! deletedIds.has(Number(photo.id)));
                        this.serverCapturedCount = Math.max(0, this.serverCapturedCount - deletedIds.size);
                        this.clearServerPhotoSelection();
                        this.serverPhotoIndex = Math.min(this.serverPhotoIndex, Math.max(0, this.serverPhotos.length - 1));
                        refreshAfterDelete = true;
                    } else if (deleteType === 'local-photo') {
                        if (! targetId) throw new Error('File foto lokal tidak ditemukan.');
                        await this.deleteLocalOnlyCapture(String(targetId));
                    } else if (deleteType === 'folder') {
                        const folderId = Number(targetId);
                        if (! Number.isInteger(folderId) || folderId < 1) throw new Error('ID folder tidak valid.');
                        const result = await this.$wire.deleteSessionFolder(folderId, confirmation);
                        if (! result?.deleted) throw new Error('Server belum menghapus folder.');
                        try {
                            await this.deleteLocalSessionCaptures(result.uuid || targetUuid);
                        } catch (error) {
                            // Folder server sudah terhapus; kegagalan membersihkan cache perangkat tidak membatalkan hasilnya.
                        }
                        this.closeServerGallery();
                        refreshAfterDelete = true;
                    } else {
                        throw new Error('Target hapus tidak ditemukan.');
                    }
                    deleted = true;
                    this.confirmOpen = false;
                } catch (error) {
                    this.confirmMessage = error?.message || 'Penghapusan gagal. Silakan coba lagi.';
                    this.backgroundState = this.confirmMessage;
                } finally {
                    this.confirmBusy = false;
                    if (deleted) {
                        this.closeConfirm();
                        if (refreshAfterDelete) this.scheduleServerRefresh(150);
                    }
                }
            },
            async deleteLocalSessionCaptures(sessionUuid) {
                if (! sessionUuid) return;
                const database = await this.openCaptureDb();
                await new Promise((resolve, reject) => {
                    const transaction = database.transaction('captures', 'readwrite');
                    const request = transaction.objectStore('captures').index('sessionUuid').openCursor(IDBKeyRange.only(sessionUuid));
                    request.onsuccess = () => {
                        const cursor = request.result;
                        if (! cursor) return;
                        cursor.delete();
                        cursor.continue();
                    };
                    transaction.oncomplete = () => resolve();
                    transaction.onerror = () => reject(transaction.error || new Error('Foto lokal folder gagal dibersihkan.'));
                });
                if (this.localGalleryFor === sessionUuid) {
                    this.localCaptures = [];
                    this.localCapturedCount = 0;
                }
            },
            scheduleServerRefresh(delay = 350) {
                window.clearTimeout(this.refreshTimer);
                if (this.serverGalleryOpen || this.confirmOpen || this.localPreviewUrl || this.serverSelectionMode) {
                    this.serverRefreshPending = true;
                    return;
                }
                this.refreshTimer = window.setTimeout(async () => {
                    if (this.serverGalleryOpen || this.confirmOpen || this.localPreviewUrl || this.serverSelectionMode) {
                        this.serverRefreshPending = true;
                    } else if (! this.cameraOpen && ! this.uploadInProgress && this.captureQueue.length === 0) {
                        this.serverRefreshPending = false;
                        this.refreshInProgress = true;

                        try {
                            await this.$wire.$refresh();
                        } finally {
                            this.refreshInProgress = false;
                            this.refreshTimer = null;
                        }
                    }
                }, delay);
            },
            createCaptureId() {
                if (window.crypto?.randomUUID) return window.crypto.randomUUID();

                return `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
            },
            async processUploadQueue() {
                if (this.uploadInProgress || this.captureQueue.length === 0) {
                    await this.completeFinishIfReady();
                    return;
                }

                const queueItem = this.captureQueue[0];
                this.uploadInProgress = true;
                this.currentUploadId = queueItem.id;
                this.uploadProgress = 0;
                this.backgroundState = `Mengirim ${this.queuedCount} foto di latar belakang`;

                try {
                    const capture = await this.getLocalCapture(queueItem.id);

                    if (! capture?.blob) {
                        this.captureQueue.shift();
                        this.queuedCount = this.captureQueue.length;
                        this.uploadInProgress = false;
                        this.currentUploadId = null;
                        this.processUploadQueue();
                        return;
                    }

                    const file = new File([capture.blob], `${capture.id}.jpg`, {
                        type: capture.blob.type || 'image/jpeg',
                        lastModified: capture.createdAt,
                    });
                    const formData = new FormData();
                    formData.append('photo', file);
                    formData.append('latitude', String(capture.latitude));
                    formData.append('longitude', String(capture.longitude));
                    if (capture.accuracy !== null && capture.accuracy !== undefined) {
                        formData.append('accuracy', String(capture.accuracy));
                    }
                    formData.append('captured_at', capture.capturedAt);
                    formData.append('client_capture_id', capture.id);
                    if (Number(capture.barangId) > 0) {
                        formData.append('barang_id', String(capture.barangId));
                    }

                    const uploadUrl = this.uploadUrlTemplate.replace(
                        '__SESSION_UUID__',
                        encodeURIComponent(capture.sessionUuid),
                    );
                    const response = await fetch(uploadUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });

                    if (! response.ok) {
                        const payload = await response.json().catch(() => ({}));
                        const validationMessage = Object.values(payload.errors || {}).flat()[0];
                        throw new Error(validationMessage || payload.message || 'Upload gagal. Foto tetap aman di perangkat.');
                    }

                    await this.handlePhotoSaved();
                } catch (error) {
                    this.handleBackgroundUploadError(error?.message || 'Upload latar belakang gagal.');
                }
            },
            async handleBackgroundUploadError(message) {
                const capture = this.captureQueue.find((item) => item.id === this.currentUploadId);
                this.uploadInProgress = false;
                this.currentUploadId = null;
                this.backgroundState = message;

                if (! capture) return;
                capture.attempts = (capture.attempts || 0) + 1;

                if (capture.attempts <= 3 && navigator.onLine) {
                    window.clearTimeout(this.queueRetryTimer);
                    this.queueRetryTimer = window.setTimeout(
                        () => this.processUploadQueue(),
                        Math.min(15000, capture.attempts * 3000),
                    );
                }
            },
            retryPendingUploads() {
                window.clearTimeout(this.queueRetryTimer);
                this.captureQueue.forEach((capture) => capture.attempts = 0);
                this.uploadInProgress = false;
                this.currentUploadId = null;
                this.processUploadQueue();
            },
            async completeFinishIfReady() {
                if (
                    ! this.finishRequested
                    || this.finishingSession
                    || this.uploadInProgress
                    || this.captureQueue.length > 0
                ) return;

                this.finishingSession = true;
                this.finishRequested = false;
                try {
                    await this.$wire.finishSession(this.finishAllowsEmptyLocal);
                    this.clearSessionRecovery();
                } finally {
                    this.finishingSession = false;
                    this.finishAllowsEmptyLocal = false;
                }
            },
            async useHandayaniTemplateLocation() {
                if (this.templateApplying) return false;

                this.gpsRequestId++;
                this.locating = false;
                this.resolvingAddress = false;
                this.templateApplying = true;
                this.cameraError = '';
                this.gpsState = 'Mengaktifkan lokasi template Handayani...';

                try {
                    const location = await this.$wire.applyHandayaniTemplateLocation();
                    this.latitude = Number(location.latitude);
                    this.longitude = Number(location.longitude);
                    this.accuracy = null;
                    this.sessionLocation = location.name;
                    this.sessionAddress = location.address;
                    this.locationMode = 'template';
                    this.gpsReady = true;
                    this.gpsState = 'Lokasi template Handayani aktif';

                    return true;
                } catch (error) {
                    this.gpsState = 'Lokasi template Handayani gagal diaktifkan';

                    return false;
                } finally {
                    this.templateApplying = false;
                }
            },
            async refreshGps(force = true) {
                if (! force && this.locationMode === 'template' && this.gpsReady) {
                    return true;
                }

                this.locationMode = 'gps';
                const requestId = ++this.gpsRequestId;

                if (! window.isSecureContext && ! ['localhost', '127.0.0.1'].includes(window.location.hostname)) {
                    this.gpsState = 'GPS membutuhkan koneksi HTTPS';
                    this.gpsReady = false;
                    return false;
                }

                if (! navigator.geolocation) {
                    this.gpsState = 'GPS tidak didukung perangkat ini';
                    this.gpsReady = false;
                    return false;
                }

                this.locating = true;
                this.gpsState = 'Meminta lokasi perangkat...';

                return await new Promise((resolve) => navigator.geolocation.getCurrentPosition(
                    async (position) => {
                        if (requestId !== this.gpsRequestId) {
                            resolve(this.gpsReady);
                            return;
                        }

                        this.latitude = position.coords.latitude;
                        this.longitude = position.coords.longitude;
                        this.accuracy = Math.max(0, Math.round(position.coords.accuracy));
                        this.resolvingAddress = true;
                        this.gpsState = 'GPS ditemukan, mencari alamat otomatis...';

                        try {
                            const location = await this.$wire.resolveSessionLocation(
                                this.latitude,
                                this.longitude,
                                this.accuracy,
                            );

                            if (requestId !== this.gpsRequestId || this.locationMode !== 'gps') {
                                resolve(this.gpsReady);
                                return;
                            }

                            this.sessionLocation = location?.name || `Lokasi GPS ${Number(this.latitude).toFixed(6)}, ${Number(this.longitude).toFixed(6)}`;
                            this.sessionAddress = location?.address || `Koordinat ${Number(this.latitude).toFixed(6)}, ${Number(this.longitude).toFixed(6)}`;
                            this.gpsReady = true;
                            this.gpsState = location?.resolved
                                ? 'GPS dan alamat otomatis aktif'
                                : 'GPS aktif - alamat otomatis belum tersedia';
                            resolve(true);
                        } catch (error) {
                            this.gpsReady = false;
                            this.gpsState = 'Alamat otomatis gagal dimuat, tekan refresh GPS';
                            resolve(false);
                        } finally {
                            if (requestId === this.gpsRequestId) {
                                this.locating = false;
                                this.resolvingAddress = false;
                            }
                        }
                    },
                    (error) => {
                        if (requestId !== this.gpsRequestId) {
                            resolve(this.gpsReady);
                            return;
                        }

                        this.gpsReady = false;
                        this.locating = false;
                        this.gpsState = error.code === 1
                            ? 'Izin lokasi belum diberikan'
                            : 'Lokasi belum valid, tekan refresh GPS';
                        resolve(false);
                    },
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
                ));
            },
            async openCamera(sessionUuid = this.sessionUuid, sessionLocation = this.sessionLocation, sessionAddress = this.sessionAddress) {
                if (this.refreshInProgress) {
                    this.backgroundState = 'Tunggu pembaruan daftar foto selesai, lalu buka kamera kembali.';
                    return;
                }

                if (this.refreshTimer) {
                    window.clearTimeout(this.refreshTimer);
                    this.refreshTimer = null;
                    this.serverRefreshPending = true;
                }

                if (! window.isSecureContext && ! ['localhost', '127.0.0.1'].includes(window.location.hostname)) {
                    this.cameraError = 'Kamera membutuhkan akses HTTPS.';
                    return;
                }

                if (! navigator.mediaDevices?.getUserMedia) {
                    this.cameraError = 'Kamera live tidak didukung browser ini.';
                    return;
                }

                this.cameraError = '';
                this.cameraReady = false;
                this.sessionUuid = sessionUuid;
                if (this.locationMode !== 'template') {
                    this.sessionLocation = sessionLocation || '';
                    this.sessionAddress = sessionAddress || '';
                }
                this.initializeCaptureQueue(sessionUuid);
                await this.loadLocalGallery(sessionUuid);
                this.cameraOpen = true;
                document.body.style.overflow = 'hidden';
                this.updateClock();
                this.clockTimer = window.setInterval(() => this.updateClock(), 1000);

                try {
                    this.cameraStream = await navigator.mediaDevices.getUserMedia({
                        audio: false,
                        video: {
                            facingMode: { ideal: 'environment' },
                            width: { ideal: 1920 },
                            height: { ideal: 1080 },
                        },
                    });
                    const video = this.$refs.cameraVideo;
                    video.srcObject = this.cameraStream;
                    await Promise.all([video.play(), this.waitForCameraReady(video)]);
                    this.cameraReady = true;
                    this.persistSessionRecovery(true);
                    const videoTrack = this.cameraStream.getVideoTracks()[0] || null;
                    this.cameraVideoTrack = videoTrack;
                    const videoCapabilities = videoTrack?.getCapabilities?.() || {};
                    this.torchSupported = Boolean(videoCapabilities.torch && videoTrack?.applyConstraints);
                    this.torchEnabled = false;
                    videoTrack?.addEventListener('mute', () => {
                        if (! this.cameraOpen) return;
                        this.cameraReady = false;
                        this.cameraError = 'Stream kamera terhenti sementara. Tunggu atau muat ulang kamera.';
                    });
                    videoTrack?.addEventListener('unmute', () => {
                        if (! this.cameraOpen) return;
                        this.cameraReady = true;
                        this.cameraError = '';
                    });
                    videoTrack?.addEventListener('ended', () => {
                        if (! this.cameraOpen) return;
                        this.cameraReady = false;
                        this.cameraError = 'Stream kamera terputus. Tekan muat ulang kamera.';
                    });
                    await this.refreshGps(false);
                } catch (error) {
                    this.cameraError = 'Kamera tidak dapat dibuka. Periksa izin kamera pada browser.';
                    this.closeCamera(false);
                }
            },
            waitForCameraReady(video) {
                return new Promise((resolve, reject) => {
                    let timeoutId;
                    const cleanup = () => {
                        window.clearTimeout(timeoutId);
                        video.removeEventListener('loadedmetadata', check);
                        video.removeEventListener('canplay', check);
                        video.removeEventListener('playing', check);
                    };
                    const check = () => {
                        if (video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0) {
                            cleanup();
                            window.requestAnimationFrame(resolve);
                        }
                    };
                    timeoutId = window.setTimeout(() => {
                        cleanup();
                        reject(new Error('Kamera belum siap.'));
                    }, 10000);
                    video.addEventListener('loadedmetadata', check);
                    video.addEventListener('canplay', check);
                    video.addEventListener('playing', check);
                    check();
                });
            },
            waitForFreshCameraFrame(video) {
                const frameIsUsable = () => Boolean(
                    this.cameraOpen
                    && this.cameraStream?.active
                    && this.cameraVideoTrack?.readyState !== 'ended'
                    && video?.readyState >= 2
                    && video.videoWidth > 0
                    && video.videoHeight > 0
                );

                if (typeof video?.requestVideoFrameCallback !== 'function') {
                    return new Promise((resolve) => {
                        window.requestAnimationFrame(() => {
                            window.requestAnimationFrame(() => resolve(frameIsUsable()));
                        });
                    });
                }

                return new Promise((resolve) => {
                    let settled = false;
                    let callbackId = null;
                    let timeoutId = null;
                    const finish = () => {
                        if (settled) return;
                        settled = true;
                        window.clearTimeout(timeoutId);
                        if (callbackId !== null && typeof video.cancelVideoFrameCallback === 'function') {
                            video.cancelVideoFrameCallback(callbackId);
                        }
                        resolve(frameIsUsable());
                    };

                    callbackId = video.requestVideoFrameCallback(finish);
                    timeoutId = window.setTimeout(finish, 650);
                });
            },
            async encodeCaptureBlob(canvas, quality) {
                for (let attempt = 0; attempt < 2; attempt++) {
                    const blob = await new Promise((resolve) => {
                        let settled = false;
                        const finish = (result) => {
                            if (settled) return;
                            settled = true;
                            window.clearTimeout(timeoutId);
                            resolve(result || null);
                        };
                        const timeoutId = window.setTimeout(() => finish(null), 1800);

                        try {
                            canvas.toBlob(finish, 'image/jpeg', quality);
                        } catch (error) {
                            finish(null);
                        }
                    });

                    if (blob) {
                        return blob;
                    }

                    await new Promise((resolve) => window.requestAnimationFrame(resolve));
                }

                throw new Error('Foto gagal dibuat oleh kamera. Silakan coba kembali.');
            },
            closeCamera(clearError = true) {
                this.cameraStream?.getTracks().forEach((track) => track.stop());
                this.cameraStream = null;
                this.cameraVideoTrack = null;
                this.cameraReady = false;
                this.torchSupported = false;
                this.torchEnabled = false;
                this.torchBusy = false;
                if (this.$refs.cameraVideo) this.$refs.cameraVideo.srcObject = null;
                if (this.clockTimer) window.clearInterval(this.clockTimer);
                this.clockTimer = null;
                this.cameraOpen = false;
                this.captureBusy = false;
                document.body.style.overflow = '';
                if (clearError) this.cameraError = '';
            },
            async toggleTorch() {
                if (this.torchBusy || ! this.torchSupported || ! this.cameraVideoTrack) return;

                this.torchBusy = true;
                const enable = ! this.torchEnabled;

                try {
                    await this.cameraVideoTrack.applyConstraints({
                        advanced: [{ torch: enable }],
                    });
                    this.torchEnabled = enable;
                    this.cameraError = '';
                } catch (error) {
                    this.torchEnabled = false;
                    this.torchSupported = false;
                    this.cameraError = 'Flash tidak tersedia pada kamera atau browser ini.';
                } finally {
                    this.torchBusy = false;
                }
            },
            async closeCameraAndRefresh() {
                this.persistSessionRecovery(false);
                this.closeCamera();
                if (! this.uploadInProgress && this.captureQueue.length === 0) {
                    this.scheduleServerRefresh(100);
                } else {
                    this.backgroundState = 'Melanjutkan pengiriman foto ke server...';
                }
            },
            async restartCamera() {
                this.closeCamera(false);
                await this.$nextTick();
                await this.openCamera();
            },
            async captureFrame() {
                if (this.captureBusy) return;

                if (! this.gpsReady || this.latitude === null || this.longitude === null) {
                    this.cameraError = 'Lokasi belum valid. Tekan refresh GPS lalu coba lagi.';
                    return;
                }

                const video = this.$refs.cameraVideo;
                if (! video) {
                    this.cameraError = 'Kamera belum siap. Tunggu sebentar lalu coba lagi.';
                    return;
                }

                this.captureBusy = true;
                this.cameraError = '';
                let shouldProcessUpload = false;

                try {
                    const frameReady = await this.waitForFreshCameraFrame(video);
                    if (! frameReady) {
                        throw new Error('Frame kamera belum siap. Tunggu sebentar lalu coba lagi.');
                    }

                    this.playShutterBeep();
                    const maxDimension = 1920;
                    const cropPerSide = this.captureMode === 'local'
                        ? Math.round(video.videoHeight * this.verticalCropRatio)
                        : 0;
                    const sourceHeight = Math.max(1, video.videoHeight - (cropPerSide * 2));
                    const scale = Math.min(1, maxDimension / Math.max(video.videoWidth, sourceHeight));
                    const canvas = this.$refs.captureCanvas;
                    canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
                    canvas.height = Math.max(1, Math.round(sourceHeight * scale));
                    const context = canvas.getContext('2d', { alpha: false });
                    context.imageSmoothingEnabled = true;
                    context.imageSmoothingQuality = 'high';
                    context.drawImage(
                        video,
                        0,
                        cropPerSide,
                        video.videoWidth,
                        sourceHeight,
                        0,
                        0,
                        canvas.width,
                        canvas.height,
                    );

                    const createdAt = Date.now();
                    if (this.captureMode === 'local') {
                        this.drawLocalWatermark(canvas, context, createdAt);
                    }

                    const blob = await this.encodeCaptureBlob(
                        canvas,
                        this.captureMode === 'local' ? 0.86 : 0.9,
                    );
                    const capture = {
                        id: this.createCaptureId(),
                        sessionUuid: this.sessionUuid,
                        mode: this.captureMode,
                        localSequence: this.captureMode === 'local' ? this.nextLocalSequence() : null,
                        createdAt,
                        capturedAt: new Date(createdAt).toISOString(),
                        latitude: this.latitude,
                        longitude: this.longitude,
                        accuracy: this.accuracy,
                        barangId: this.captureMode === 'server' ? this.selectedBarangId : null,
                        fileSize: blob.size,
                        attempts: 0,
                        blob,
                    };

                    await this.saveLocalCaptureWithRetry(capture);
                    const captureMetadata = { ...capture };
                    delete captureMetadata.blob;

                    if (this.captureMode === 'server') {
                        this.captureQueue.push(captureMetadata);
                        this.captureQueue.sort((first, second) => first.createdAt - second.createdAt);
                        this.queuedCount = this.captureQueue.length;
                        this.capturedCount++;
                        this.backgroundState = `${this.queuedCount} foto aman di perangkat`;
                        shouldProcessUpload = true;
                    } else {
                        this.localCaptures.unshift(captureMetadata);
                        this.localCapturedCount = this.localCaptures.length;
                        this.backgroundState = `${this.localCapturedCount} foto tersimpan lokal di perangkat`;
                    }

                    this.persistSessionRecovery(true);

                    this.$refs.cameraFlash?.classList.add('is-visible');
                    window.setTimeout(() => this.$refs.cameraFlash?.classList.remove('is-visible'), 140);
                } catch (error) {
                    this.cameraError = error?.message || 'Foto gagal diamankan. Silakan potret ulang.';
                } finally {
                    this.captureBusy = false;
                }

                if (shouldProcessUpload) this.processUploadQueue();
            },
            async handlePhotoSaved() {
                this.uploadProgress = 100;
                const uploadedId = this.currentUploadId;

                if (! uploadedId) {
                    this.serverCapturedCount++;
                    this.capturedCount++;
                    if (! this.cameraOpen) this.scheduleServerRefresh();
                    return;
                }

                try {
                    await this.deleteLocalCapture(uploadedId);
                } catch (error) {
                    // ID capture pada server mencegah duplikasi bila pembersihan lokal gagal.
                }

                this.captureQueue = this.captureQueue.filter((capture) => capture.id !== uploadedId);
                this.serverCapturedCount++;
                this.queuedCount = this.captureQueue.length;
                this.currentUploadId = null;
                this.uploadInProgress = false;
                this.persistSessionRecovery(this.cameraOpen);
                this.backgroundState = this.queuedCount > 0
                    ? `${this.queuedCount} foto aman, melanjutkan upload`
                    : 'Semua foto sudah aman di server';

                if (this.queuedCount > 0) {
                    this.processUploadQueue();
                } else {
                    await this.completeFinishIfReady();

                    if (! this.cameraOpen) this.scheduleServerRefresh(500);
                }
            },
            handlePhotoFailed() {
                if (this.currentUploadId) {
                    this.handleBackgroundUploadError('Server belum menerima foto. Salinan lokal tetap aman.');
                    return;
                }

                this.cameraError = 'Foto gagal disimpan server. Silakan potret ulang.';
            },
            async finishSessionFromPage() {
                this.persistSessionRecovery(false);
                this.finishRequested = true;
                this.finishAllowsEmptyLocal = this.localCapturedCount > 0;
                this.backgroundState = this.captureQueue.length > 0 || this.uploadInProgress
                    ? 'Menunggu semua foto aman di server sebelum menyelesaikan sesi'
                    : this.backgroundState;
                await this.completeFinishIfReady();
            },
            async shareSessionArchive(archiveUrl, archiveFileName, sessionTitle) {
                if (! archiveUrl) return false;

                try {
                    const response = await fetch(archiveUrl, { credentials: 'same-origin' });
                    if (! response.ok) throw new Error('Arsip sesi tidak dapat disiapkan.');
                    const blob = await response.blob();
                    const file = new File([blob], archiveFileName, {
                        type: blob.type || 'application/zip',
                    });

                    if (navigator.share && navigator.canShare?.({ files: [file] })) {
                        await navigator.share({
                            title: sessionTitle,
                            text: `Semua foto ${sessionTitle} - Logistik Handayani`,
                            files: [file],
                        });
                        this.shareAllStatus = 'Arsip seluruh foto berhasil dibagikan.';

                        return true;
                    }
                } catch (error) {
                    if (error?.name === 'AbortError') throw error;
                }

                window.location.href = archiveUrl;
                this.shareAllStatus = 'Perangkat tidak mendukung berbagi file; ZIP sedang diunduh.';

                return false;
            },
            async shareAllSessionPhotos(archiveUrl, archiveFileName, sessionTitle) {
                if (this.shareAllBusy) return;
                if (this.uploadInProgress || this.captureQueue.length > 0) {
                    this.shareAllStatus = 'Tunggu seluruh antrean selesai dikirim sebelum membagikan sesi.';
                    return;
                }

                this.syncServerPhotosFromDom();
                const totalFiles = this.serverPhotos.length + this.localCaptures.length;
                const estimatedBytes = [...this.serverPhotos, ...this.localCaptures].reduce(
                    (total, photo) => total + Number(photo.fileSize || 0),
                    0,
                );

                if (totalFiles === 0) {
                    this.shareAllStatus = 'Belum ada foto yang dapat dibagikan.';
                    return;
                }

                if (estimatedBytes > 100 * 1024 * 1024) {
                    if (this.localCaptures.length === 0) {
                        this.shareAllStatus = 'Sesi berukuran besar, menyiapkan satu arsip ZIP agar perangkat tetap ringan...';
                        this.shareAllBusy = true;
                        try {
                            await this.shareSessionArchive(archiveUrl, archiveFileName, sessionTitle);
                        } finally {
                            this.shareAllBusy = false;
                        }
                    } else {
                        this.shareAllStatus = 'Ukuran seluruh foto lokal melebihi 100 MB. Bagikan dalam beberapa bagian agar HP tetap stabil.';
                    }

                    return;
                }

                this.shareAllBusy = true;
                this.shareAllProgress = 0;
                this.shareAllStatus = 'Menyiapkan seluruh foto...';

                try {
                    if (! navigator.share || ! navigator.canShare) {
                        if (this.localCaptures.length === 0) {
                            await this.shareSessionArchive(archiveUrl, archiveFileName, sessionTitle);
                        } else {
                            this.shareAllStatus = 'Browser ini belum mendukung berbagi banyak foto. Gunakan tombol bagikan pada tiap foto lokal.';
                        }

                        return;
                    }

                    const files = [];
                    let totalBytes = 0;
                    let processedFiles = 0;

                    for (const photo of this.serverPhotos) {
                        const response = await fetch(photo.preview, { credentials: 'same-origin' });
                        const contentType = response.headers.get('content-type') || '';
                        if (! response.ok || ! contentType.startsWith('image/')) {
                            throw new Error('Salah satu foto server tidak dapat dibaca.');
                        }

                        const blob = await response.blob();
                        totalBytes += blob.size;
                        files.push(new File([blob], photo.fileName, {
                            type: blob.type || 'image/jpeg',
                        }));
                        processedFiles++;
                        this.shareAllProgress = Math.round((processedFiles / totalFiles) * 100);
                    }

                    for (const metadata of this.localCaptures) {
                        const capture = await this.getLocalCapture(metadata.id);
                        if (! capture?.blob) throw new Error('Salah satu foto lokal tidak ditemukan.');
                        totalBytes += capture.blob.size;
                        files.push(new File([capture.blob], this.localCaptureFileName(capture), {
                            type: capture.blob.type || 'image/jpeg',
                            lastModified: capture.createdAt,
                        }));
                        processedFiles++;
                        this.shareAllProgress = Math.round((processedFiles / totalFiles) * 100);
                    }

                    if (totalBytes > 100 * 1024 * 1024 || ! navigator.canShare({ files })) {
                        if (this.localCaptures.length === 0) {
                            await this.shareSessionArchive(archiveUrl, archiveFileName, sessionTitle);
                        } else {
                            this.shareAllStatus = 'Jumlah foto terlalu besar untuk dibagikan sekaligus oleh perangkat ini.';
                        }

                        return;
                    }

                    await navigator.share({
                        title: sessionTitle,
                        text: `Laporan ${totalFiles} foto barang datang - Logistik Handayani`,
                        files,
                    });
                    this.shareAllStatus = `${totalFiles} foto berhasil dikirim ke menu berbagi.`;
                } catch (error) {
                    if (error?.name === 'AbortError') {
                        this.shareAllStatus = 'Berbagi dibatalkan.';
                        return;
                    }

                    if (this.localCaptures.length === 0) {
                        this.shareAllStatus = 'Berbagi foto langsung gagal, menyiapkan arsip ZIP...';
                        await this.shareSessionArchive(archiveUrl, archiveFileName, sessionTitle);
                    } else {
                        this.shareAllStatus = error?.message || 'Foto belum dapat dibagikan sekaligus.';
                    }
                } finally {
                    this.shareAllBusy = false;
                    this.shareAllProgress = 0;
                }
            },
            async sharePhoto(previewUrl, downloadUrl, fileName) {
                try {
                    const response = await fetch(previewUrl, { credentials: 'same-origin' });
                    const blob = await response.blob();
                    const file = new File([blob], fileName, { type: blob.type || 'image/jpeg' });

                    if (navigator.share && navigator.canShare?.({ files: [file] })) {
                        await navigator.share({
                            title: 'Foto barang datang',
                            text: 'Laporan foto barang datang Logistik Handayani',
                            files: [file],
                        });
                        return;
                    }
                } catch (error) {
                    // Unduhan biasa menjadi fallback bila fitur berbagi tidak tersedia.
                }

                window.location.href = downloadUrl;
            },
        });

window.fotoBarangMaps = fotoBarangMaps;
