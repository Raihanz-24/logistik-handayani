<x-filament-panels::page>
    @php
        $activeSession = $this->activeSession();
        $sessions = $this->sessions();
    @endphp

    <div
        class="fm-page"
        x-data="{
            gpsState: 'Mencari lokasi GPS...',
            gpsReady: false,
            locating: false,
            latitude: @js($latitude),
            longitude: @js($longitude),
            accuracy: @js($accuracy),
            cameraOpen: false,
            cameraStream: null,
            cameraReady: false,
            cameraError: '',
            captureBusy: false,
            captureMode: 'server',
            sessionLocation: @js($activeSession?->nama_lokasi ?? ''),
            sessionAddress: @js($activeSession?->alamat ?? ''),
            uploadInProgress: false,
            uploadProgress: 0,
            queuedCount: 0,
            capturedCount: @js((int) ($activeSession?->items_count ?? 0)),
            serverCapturedCount: @js((int) ($activeSession?->items_count ?? 0)),
            sessionUuid: @js($activeSession?->uuid),
            captureQueue: [],
            localCaptures: [],
            localCapturedCount: 0,
            localGalleryFor: null,
            localPreviewUrl: null,
            localPreviewCapture: null,
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
            initCamera() {
                this.gpsState = 'GPS akan diambil saat kamera dibuka';
                this.gpsReady = this.latitude !== null && this.longitude !== null;
                this.updateClock();
                this.initializeCaptureQueue();
                this.loadLocalGallery();
            },
            destroy() {
                this.closeCamera();
                this.closeLocalPreview();
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
                    request.onsuccess = () => resolve(request.result);
                    request.onerror = () => reject(request.error || new Error('Penyimpanan perangkat gagal dibuka.'));
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
                            captures.push(metadata);
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
                const timeFont = Math.max(42, base * 0.076);
                const dateFont = Math.max(24, base * 0.039);
                const locationFont = Math.max(21, base * 0.032);
                const addressFont = Math.max(17, base * 0.0225);
                const coordinateFont = Math.max(15, base * 0.019);
                context.font = `600 ${addressFont}px "Roboto Condensed", "Arial Narrow", Arial, sans-serif`;
                const addressLines = this.wrapCanvasText(context, this.sessionAddress, width - (contentX * 2), 2);
                const timeRowHeight = timeFont * 1.12;
                const locationLineHeight = locationFont * 1.18;
                const addressLineHeight = addressFont * 1.14;
                const outerBottomSpace = padding * 0.22;
                const innerBottomSpace = padding * 0.22;
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

                const badgeFont = Math.max(13, base * 0.017);
                const badgeText = 'HANDAYANI MAP CAMERA';
                context.font = `800 ${badgeFont}px "Roboto Condensed", "Arial Narrow", Arial, sans-serif`;
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
                context.font = `800 ${timeFont}px "Roboto Condensed", "Arial Narrow", Arial, sans-serif`;
                context.fillText(time, contentX, y);
                const timeWidth = context.measureText(time).width;
                const dividerX = contentX + timeWidth + padding * 0.75;
                const dividerHeight = timeRowHeight;
                context.fillStyle = '#f7b500';
                context.fillRect(dividerX, y, Math.max(4, base * 0.004), dividerHeight);

                const dateX = dividerX + padding * 0.55;
                context.fillStyle = '#ffffff';
                context.font = `800 ${dateFont}px "Roboto Condensed", "Arial Narrow", Arial, sans-serif`;
                context.fillText(dateText, dateX, y);
                context.fillText(dayText, dateX, y + dateFont * 1.02);
                y += dividerHeight + padding * 0.28;

                context.font = `800 ${locationFont}px "Roboto Condensed", "Arial Narrow", Arial, sans-serif`;
                context.fillStyle = '#ffffff';
                const locationLines = this.wrapCanvasText(context, this.sessionLocation, width - (contentX * 2), 1);
                context.fillText(locationLines[0] || '-', contentX, y);
                const flagX = Math.min(width - contentX - locationFont * 1.45, contentX + context.measureText(locationLines[0] || '-').width + padding * .35);
                context.fillStyle = '#ef4444';
                context.fillRect(flagX, y + locationFont * .12, locationFont * 1.25, locationFont * .38);
                context.fillStyle = '#ffffff';
                context.fillRect(flagX, y + locationFont * .5, locationFont * 1.25, locationFont * .38);
                y += locationLineHeight;

                context.font = `600 ${addressFont}px "Roboto Condensed", "Arial Narrow", Arial, sans-serif`;
                context.fillStyle = '#f3f5f8';
                for (const line of addressLines) {
                    context.fillText(line, contentX, y);
                    y += addressLineHeight;
                }

                context.font = `600 ${coordinateFont}px "Roboto Condensed", "Arial Narrow", Arial, sans-serif`;
                context.fillStyle = '#e6ebf1';
                const accuracyText = this.accuracy === null ? '' : ` | Akurasi +/-${this.accuracy} m`;
                context.fillText(
                    `Lat ${Number(this.latitude).toFixed(6)} | Long ${Number(this.longitude).toFixed(6)}${accuracyText}`,
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
            },
            async deleteLocalOnlyCapture(captureId) {
                if (! window.confirm('Hapus foto lokal ini? File tidak dapat dipulihkan.')) return;
                await this.deleteLocalCapture(captureId);
                this.localCaptures = this.localCaptures.filter((capture) => capture.id !== captureId);
                this.localCapturedCount = this.localCaptures.length;
                if (this.localPreviewCapture?.id === captureId) this.closeLocalPreview();
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

                    await $wire.updateCaptureMetadata(
                        capture.latitude,
                        capture.longitude,
                        capture.accuracy,
                        capture.capturedAt,
                        capture.id,
                    );
                    const file = new File([capture.blob], `${capture.id}.jpg`, {
                        type: capture.blob.type || 'image/jpeg',
                        lastModified: capture.createdAt,
                    });

                    $wire.upload(
                        'photo',
                        file,
                        () => {},
                        () => this.handleBackgroundUploadError('Upload gagal. Foto tetap aman di perangkat.'),
                        (event) => this.uploadProgress = event.detail?.progress ?? 0,
                    );
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
                await $wire.finishSession(this.finishAllowsEmptyLocal);
                this.finishingSession = false;
                this.finishAllowsEmptyLocal = false;
            },
            async refreshGps() {
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
                        this.latitude = position.coords.latitude;
                        this.longitude = position.coords.longitude;
                        this.accuracy = Math.max(0, Math.round(position.coords.accuracy));
                        await $wire.updateCoordinates(this.latitude, this.longitude, this.accuracy);
                        this.gpsReady = true;
                        this.locating = false;
                        this.gpsState = this.accuracy > 100
                            ? `Akurasi rendah (+/-${this.accuracy} m), tekan refresh`
                            : `GPS aktif - akurasi +/-${this.accuracy} meter`;
                        resolve(true);
                    },
                    (error) => {
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
            async useDefaultGps() {
                if (! this.cameraOpen) {
                    await $wire.useDefaultLocation();
                }
                this.latitude = @js((float) config('foto_barang.default_latitude'));
                this.longitude = @js((float) config('foto_barang.default_longitude'));
                this.accuracy = null;
                this.gpsReady = true;
                this.gpsState = 'Menggunakan lokasi default Paiton';
            },
            async openCamera(sessionUuid = this.sessionUuid, sessionLocation = this.sessionLocation, sessionAddress = this.sessionAddress) {
                if (! window.isSecureContext && ! ['localhost', '127.0.0.1'].includes(window.location.hostname)) {
                    this.cameraError = 'Kamera membutuhkan akses HTTPS.';
                    return;
                }

                if (! navigator.mediaDevices?.getUserMedia) {
                    this.cameraError = 'Kamera live tidak didukung browser ini. Gunakan tombol kamera alternatif.';
                    return;
                }

                this.cameraError = '';
                this.cameraReady = false;
                this.sessionUuid = sessionUuid;
                this.sessionLocation = sessionLocation || '';
                this.sessionAddress = sessionAddress || '';
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
                    const videoTrack = this.cameraStream.getVideoTracks()[0];
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
                    await this.refreshGps();
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
            closeCamera(clearError = true) {
                this.cameraStream?.getTracks().forEach((track) => track.stop());
                this.cameraStream = null;
                this.cameraReady = false;
                if (this.$refs.cameraVideo) this.$refs.cameraVideo.srcObject = null;
                if (this.clockTimer) window.clearInterval(this.clockTimer);
                this.clockTimer = null;
                this.cameraOpen = false;
                this.captureBusy = false;
                document.body.style.overflow = '';
                if (clearError) this.cameraError = '';
            },
            async closeCameraAndRefresh() {
                this.closeCamera();
                if (! this.uploadInProgress && this.captureQueue.length === 0) {
                    await $wire.$refresh();
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
                    this.cameraError = 'Lokasi belum valid. Tekan refresh GPS atau gunakan lokasi default.';
                    return;
                }

                const video = this.$refs.cameraVideo;
                if (! this.cameraReady || ! video?.videoWidth || ! video?.videoHeight || video.readyState < 2) {
                    this.cameraError = 'Kamera belum siap. Tunggu sebentar lalu coba lagi.';
                    return;
                }

                this.captureBusy = true;
                this.cameraError = '';

                try {
                    const maxDimension = 1920;
                    const scale = Math.min(1, maxDimension / Math.max(video.videoWidth, video.videoHeight));
                    const canvas = this.$refs.captureCanvas;
                    canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
                    canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
                    const context = canvas.getContext('2d', { alpha: false });
                    context.imageSmoothingEnabled = true;
                    context.imageSmoothingQuality = 'high';
                    context.drawImage(video, 0, 0, canvas.width, canvas.height);

                    const createdAt = Date.now();
                    if (this.captureMode === 'local') {
                        this.drawLocalWatermark(canvas, context, createdAt);
                    }

                    const blob = await new Promise((resolve, reject) => canvas.toBlob(
                        (result) => result ? resolve(result) : reject(new Error('Foto gagal dibuat.')),
                        'image/jpeg',
                        this.captureMode === 'local' ? 0.86 : 0.9,
                    ));
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
                        attempts: 0,
                        blob,
                    };

                    await this.saveLocalCapture(capture);
                    const captureMetadata = { ...capture };
                    delete captureMetadata.blob;

                    if (this.captureMode === 'server') {
                        this.captureQueue.push(captureMetadata);
                        this.captureQueue.sort((first, second) => first.createdAt - second.createdAt);
                        this.queuedCount = this.captureQueue.length;
                        this.capturedCount++;
                        this.backgroundState = `${this.queuedCount} foto aman di perangkat`;
                    } else {
                        this.localCaptures.unshift(captureMetadata);
                        this.localCapturedCount = this.localCaptures.length;
                        this.backgroundState = `${this.localCapturedCount} foto tersimpan lokal di perangkat`;
                    }

                    this.$refs.cameraFlash?.classList.add('is-visible');
                    window.setTimeout(() => this.$refs.cameraFlash?.classList.remove('is-visible'), 140);
                    this.captureBusy = false;
                    if (this.captureMode === 'server') this.processUploadQueue();
                } catch (error) {
                    this.captureBusy = false;
                    this.cameraError = error?.message || 'Foto gagal diamankan. Silakan potret ulang.';
                }
            },
            async handlePhotoSaved() {
                this.uploadProgress = 100;
                const uploadedId = this.currentUploadId;

                if (! uploadedId) {
                    this.serverCapturedCount++;
                    this.capturedCount++;
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
                this.backgroundState = this.queuedCount > 0
                    ? `${this.queuedCount} foto aman, melanjutkan upload`
                    : 'Semua foto sudah aman di server';

                if (this.queuedCount > 0) {
                    this.processUploadQueue();
                } else {
                    await this.completeFinishIfReady();

                    if (! this.cameraOpen && ! this.finishRequested) {
                        window.setTimeout(() => $wire.$refresh(), 900);
                    }
                }
            },
            handlePhotoFailed() {
                if (this.currentUploadId) {
                    this.handleBackgroundUploadError('Server belum menerima foto. Salinan lokal tetap aman.');
                    return;
                }

                this.cameraError = 'Foto gagal disimpan server. Silakan potret ulang.';
            },
            async finishCaptureSession() {
                if (this.captureBusy) return;
                if (! window.confirm('Selesaikan sesi foto ini? Kamera akan ditutup.')) return;
                this.closeCamera();
                this.finishRequested = true;
                this.finishAllowsEmptyLocal = this.captureMode === 'local';
                this.backgroundState = this.captureQueue.length > 0 || this.uploadInProgress
                    ? 'Menunggu semua foto aman di server sebelum menyelesaikan sesi'
                    : this.backgroundState;
                await this.completeFinishIfReady();
            },
            async finishSessionFromPage() {
                if (! window.confirm('Selesaikan sesi ini? Setelah selesai, foto server tidak dapat ditambah atau dihapus.')) return;
                this.finishRequested = true;
                this.finishAllowsEmptyLocal = this.localCapturedCount > 0;
                this.backgroundState = this.captureQueue.length > 0 || this.uploadInProgress
                    ? 'Menunggu semua foto aman di server sebelum menyelesaikan sesi'
                    : this.backgroundState;
                await this.completeFinishIfReady();
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
        }"
        x-init="initCamera()"
        x-on:foto-barang-saved.window="handlePhotoSaved()"
        x-on:foto-barang-failed.window="handlePhotoFailed()"
        x-on:foto-barang-deleted.window="capturedCount = Math.max(0, capturedCount - 1)"
        x-on:online.window="retryPendingUploads()"
    >
        <section class="fm-hero">
            <div class="fm-hero__copy">
                <span class="fm-eyebrow">Handayani Map Camera</span>
                <h1>Foto barang datang, rapi per sesi.</h1>
                <p>
                    Setiap foto otomatis diberi waktu, alamat, dan koordinat GPS, kemudian dikompres menjadi JPEG yang tetap jelas untuk laporan WhatsApp.
                </p>
            </div>

            <div class="fm-flow" aria-label="Alur penggunaan">
                <span><b>1</b> Mulai sesi</span>
                <i></i>
                <span><b>2</b> Foto berurutan</span>
                <i></i>
                <span><b>3</b> Selesai & unduh</span>
            </div>
        </section>

        @if (! $activeSession)
            <section class="fm-start-card">
                <div class="fm-section-heading">
                    <div>
                        <span class="fm-section-kicker">Folder baru</span>
                        <h2>Mulai sesi foto barang</h2>
                        <p>Nama lokasi dan alamat cukup diisi sekali, lalu dipakai pada seluruh foto dalam sesi ini.</p>
                    </div>
                    <x-filament::icon icon="heroicon-o-folder-plus" />
                </div>

                <form wire:submit="startSession" class="fm-form">
                    <label class="fm-field fm-field--full">
                        <span>Nama sesi</span>
                        <input type="text" wire:model="judul" maxlength="150" placeholder="Contoh: Barang datang supplier dapur">
                        @error('judul') <small>{{ $message }}</small> @enderror
                    </label>

                    <label class="fm-field fm-field--full">
                        <span>Nama lokasi pada foto</span>
                        <input type="text" wire:model="namaLokasi" maxlength="255">
                        @error('namaLokasi') <small>{{ $message }}</small> @enderror
                    </label>

                    <label class="fm-field fm-field--full">
                        <span>Alamat lengkap pada foto</span>
                        <textarea wire:model="alamat" rows="3" maxlength="1000"></textarea>
                        @error('alamat') <small>{{ $message }}</small> @enderror
                    </label>

                    <div class="fm-form__footer">
                        <p><x-filament::icon icon="heroicon-o-shield-check" /> Tidak berhubungan dengan stok atau mutasi barang.</p>
                        <x-filament::button type="submit" icon="heroicon-m-camera" wire:loading.attr="disabled">
                            Mulai Sesi Foto
                        </x-filament::button>
                    </div>
                </form>
            </section>
        @else
            <section class="fm-session-header">
                <div>
                    <div class="fm-session-header__meta">
                        <span @class(['fm-status', 'fm-status--done' => ! $activeSession->isActive()])>
                            {{ $activeSession->isActive() ? 'Sesi aktif' : 'Sesi selesai' }}
                        </span>
                        <span>{{ $activeSession->code() }}</span>
                    </div>
                    <h2>{{ $activeSession->judul }}</h2>
                    <p>
                        {{ $activeSession->nama_lokasi }} · {{ $activeSession->items_count }} foto server
                        <span x-show="localGalleryFor === @js($activeSession->uuid) && localCapturedCount > 0" x-cloak>
                            · <b x-text="localCapturedCount"></b> foto lokal HP
                        </span>
                    </p>
                </div>

                <div class="fm-session-actions">
                    @if ($activeSession->items_count > 0)
                        <x-filament::button
                            tag="a"
                            :href="route('foto-barang.archive', $activeSession)"
                            color="gray"
                            icon="heroicon-m-arrow-down-tray"
                        >
                            Unduh Semua ZIP
                        </x-filament::button>
                    @endif

                    @if ($activeSession->isActive())
                        <x-filament::button
                            type="button"
                            color="success"
                            icon="heroicon-m-check-circle"
                            x-on:click="finishSessionFromPage()"
                        >
                            Selesaikan Sesi
                        </x-filament::button>
                    @else
                        <x-filament::button type="button" icon="heroicon-m-folder-plus" wire:click="newSession">
                            Buat Sesi Baru
                        </x-filament::button>
                    @endif
                </div>
            </section>

            <div class="fm-background-queue" x-show="queuedCount > 0 || uploadInProgress" x-cloak>
                <x-filament::icon icon="heroicon-m-cloud-arrow-up" />
                <span x-text="backgroundState"></span>
                <b x-show="uploadInProgress"><span x-text="uploadProgress"></span>%</b>
                <button type="button" x-show="! uploadInProgress" x-on:click="retryPendingUploads()">Kirim ulang</button>
            </div>

            @if ($activeSession->isActive())
                <div class="fm-workspace">
                    <section class="fm-capture-card">
                        <div class="fm-section-heading fm-section-heading--compact">
                            <div>
                                <span class="fm-section-kicker">Foto berikutnya</span>
                                <h2 x-text="'Ambil foto ke-' + ((captureMode === 'local' ? localCapturedCount : capturedCount) + 1)"></h2>
                            </div>
                            <span class="fm-counter" x-text="captureMode === 'local' ? localCapturedCount : capturedCount"></span>
                        </div>

                        <div :class="gpsReady ? 'fm-gps fm-gps--ready' : 'fm-gps'">
                            <div class="fm-gps__icon">
                                <x-filament::icon icon="heroicon-o-map-pin" />
                            </div>
                            <div>
                                <strong x-text="gpsState">Mencari lokasi GPS...</strong>
                                <span>
                                    @if ($latitude !== null && $longitude !== null)
                                        {{ number_format($latitude, 6) }}, {{ number_format($longitude, 6) }}
                                    @else
                                        Koordinat akan dicetak pada foto
                                    @endif
                                </span>
                            </div>
                            <button type="button" x-on:click="refreshGps()" x-bind:disabled="locating">Refresh GPS</button>
                        </div>

                        <div class="fm-location-fallback">
                            <p>Jika GPS browser ditolak, gunakan titik Paiton hanya bila foto memang diambil di lokasi ini.</p>
                            <button type="button" x-on:click="useDefaultGps()">Gunakan lokasi default Paiton</button>
                        </div>

                        <div class="fm-mode-picker" role="group" aria-label="Pilih penyimpanan foto">
                            <button
                                type="button"
                                x-on:click="captureMode = 'server'"
                                x-bind:class="captureMode === 'server' && 'is-active'"
                            >
                                <span><x-filament::icon icon="heroicon-o-cloud-arrow-up" /></span>
                                <strong>Mode Server</strong>
                                <small>Aman di server dan bisa dibuka dari perangkat lain</small>
                            </button>
                            <button
                                type="button"
                                x-on:click="captureMode = 'local'"
                                x-bind:class="captureMode === 'local' && 'is-active'"
                            >
                                <span><x-filament::icon icon="heroicon-o-device-phone-mobile" /></span>
                                <strong>Mode Lokal HP</strong>
                                <small>Tanpa upload, paling cepat dan hanya ada di perangkat ini</small>
                            </button>
                        </div>

                        <div class="fm-local-warning" x-show="captureMode === 'local'" x-cloak>
                            <x-filament::icon icon="heroicon-o-information-circle" />
                            <p>Foto disimpan di penyimpanan aplikasi pada HP ini. Unduh atau bagikan foto penting sebelum membersihkan data browser.</p>
                        </div>

                        <button
                            type="button"
                            class="fm-open-camera"
                            x-on:click="openCamera(@js($activeSession->uuid), @js($activeSession->nama_lokasi), @js($activeSession->alamat))"
                        >
                            <span><x-filament::icon icon="heroicon-o-camera" /></span>
                            <span>
                                <strong>Buka Kamera Berkelanjutan</strong>
                                <small>Foto banyak barang tanpa keluar dari kamera</small>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" />
                        </button>

                        <p class="fm-camera-error" x-show="cameraError && ! cameraOpen" x-text="cameraError" x-cloak></p>

                        <div class="fm-camera-box" wire:key="camera-input-{{ $uploadKey }}" x-show="captureMode === 'server'">
                            <input
                                id="foto-barang-camera-{{ $uploadKey }}"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                capture="environment"
                                wire:model="photo"
                            >

                            <label for="foto-barang-camera-{{ $uploadKey }}" class="fm-camera-button">
                                <span><x-filament::icon icon="heroicon-o-camera" /></span>
                                <strong>Kamera / Galeri Alternatif</strong>
                                <small>Kamera belakang · maksimal 10 MB</small>
                            </label>

                            <div class="fm-processing" wire:loading.flex wire:target="photo,savePhoto">
                                <span class="fm-spinner"></span>
                                <strong>Memproses foto...</strong>
                                <small>Menambahkan maps dan mengompres otomatis</small>
                            </div>
                        </div>

                        @error('photo') <p class="fm-error">{{ $message }}</p> @enderror
                        @error('latitude') <p class="fm-error">{{ $message }}</p> @enderror

                        @if ($photo)
                            <div class="fm-pending-photo">
                                <p>Foto sudah dipilih dan menunggu koordinat lokasi.</p>
                                <x-filament::button type="button" wire:click="savePhoto" wire:loading.attr="disabled">
                                    Proses Foto Sekarang
                                </x-filament::button>
                            </div>
                        @endif

                        <div class="fm-capture-note">
                            <x-filament::icon icon="heroicon-o-bolt" />
                            <p>Setelah satu foto selesai tersimpan, tombol kamera langsung siap untuk barang berikutnya.</p>
                        </div>
                    </section>

                    <aside class="fm-template-preview">
                        <span class="fm-section-kicker">Template hasil</span>
                        <div class="fm-template-frame">
                            <div class="fm-template-image">
                                <x-filament::icon icon="heroicon-o-photo" />
                                <span>Area foto barang</span>
                            </div>
                            <div class="fm-template-overlay">
                                <small>HANDAYANI MAP CAMERA</small>
                                <div><strong>{{ now('Asia/Jakarta')->format('H:i') }} WIB</strong><i></i><b>{{ now('Asia/Jakarta')->locale('id')->translatedFormat('d M Y') }}<br>{{ now('Asia/Jakarta')->locale('id')->translatedFormat('l') }}</b></div>
                                <h3>{{ $activeSession->nama_lokasi }} 🇮🇩</h3>
                                <p>{{ $activeSession->alamat }}</p>
                                <span>Lat {{ number_format($latitude ?? config('foto_barang.default_latitude'), 6) }} · Long {{ number_format($longitude ?? config('foto_barang.default_longitude'), 6) }}</span>
                            </div>
                        </div>
                    </aside>
                </div>

                <div
                    class="fm-live-camera"
                    x-show="cameraOpen"
                    x-cloak
                    x-transition.opacity.duration.180ms
                    wire:ignore
                    role="dialog"
                    aria-modal="true"
                    aria-label="Kamera foto barang"
                    x-on:keydown.escape.window="if (cameraOpen && ! captureBusy) closeCameraAndRefresh()"
                >
                    <header class="fm-live-camera__header">
                        <button type="button" x-on:click="closeCameraAndRefresh()" x-bind:disabled="captureBusy" aria-label="Tutup kamera">
                            <x-filament::icon icon="heroicon-m-x-mark" />
                        </button>
                        <div>
                            <strong>{{ $activeSession->judul }}</strong>
                            <span>
                                <b x-text="captureMode === 'local' ? localCapturedCount : capturedCount"></b>
                                foto tersimpan · <b x-text="captureMode === 'local' ? 'Lokal HP' : 'Server'"></b>
                            </span>
                        </div>
                        <button type="button" x-on:click="refreshGps()" x-bind:disabled="locating || captureBusy" aria-label="Refresh GPS">
                            <x-filament::icon icon="heroicon-m-arrow-path" x-bind:class="locating && 'is-spinning'" />
                        </button>
                    </header>

                    <main class="fm-live-camera__stage">
                        <video x-ref="cameraVideo" autoplay playsinline muted></video>
                        <canvas x-ref="captureCanvas" hidden></canvas>
                        <div class="fm-live-camera__shade"></div>

                        <div class="fm-live-camera__watermark" aria-hidden="true">
                            <div class="fm-live-camera__badge"><i></i> HANDAYANI MAP CAMERA</div>
                            <div class="fm-live-camera__datetime">
                                <strong><span x-text="liveTime"></span> WIB</strong>
                                <i></i>
                                <b><span x-text="liveDate"></span><br><span x-text="liveDay"></span></b>
                            </div>
                            <h3>{{ $activeSession->nama_lokasi }} <span>🇮🇩</span></h3>
                            <p>{{ $activeSession->alamat }}</p>
                            <small>
                                Lat <span x-text="latitude === null ? '-' : Number(latitude).toFixed(6)"></span>
                                &nbsp; Long <span x-text="longitude === null ? '-' : Number(longitude).toFixed(6)"></span>
                                <template x-if="accuracy !== null"><span>&nbsp; Akurasi +/-<b x-text="accuracy"></b> m</span></template>
                            </small>
                        </div>

                        <div class="fm-live-camera__flash" x-ref="cameraFlash"></div>
                    </main>

                    <footer class="fm-live-camera__controls">
                        <div class="fm-live-camera__gps" x-bind:class="gpsReady ? 'is-ready' : 'is-warning'">
                            <x-filament::icon icon="heroicon-m-map-pin" />
                            <span x-text="gpsState"></span>
                            <button type="button" x-show="! gpsReady" x-on:click="useDefaultGps()">Pakai default</button>
                        </div>

                        <div class="fm-live-camera__queue" x-show="captureMode === 'server' && (queuedCount > 0 || uploadInProgress)" x-cloak>
                            <x-filament::icon icon="heroicon-m-cloud-arrow-up" />
                            <span x-text="backgroundState"></span>
                            <b x-show="uploadInProgress"><span x-text="uploadProgress"></span>%</b>
                            <button type="button" x-show="! uploadInProgress" x-on:click="retryPendingUploads()">Kirim ulang</button>
                        </div>

                        <div class="fm-live-camera__queue is-local" x-show="captureMode === 'local'" x-cloak>
                            <x-filament::icon icon="heroicon-m-device-phone-mobile" />
                            <span><b x-text="localCapturedCount"></b> foto aman di perangkat · tidak diunggah</span>
                        </div>

                        <div class="fm-live-camera__actions">
                            <button type="button" class="fm-live-camera__finish" x-on:click="finishCaptureSession()" x-bind:disabled="captureBusy">
                                Selesai
                            </button>
                            <button
                                type="button"
                                class="fm-live-camera__shutter"
                                x-on:click="captureFrame()"
                                x-bind:disabled="captureBusy || ! cameraReady"
                                aria-label="Ambil foto"
                            ><span></span></button>
                            <div class="fm-live-camera__sequence">
                                <strong>#<span x-text="String((captureMode === 'local' ? localCapturedCount : capturedCount) + 1).padStart(2, '0')"></span></strong>
                                <small>berikutnya</small>
                            </div>
                        </div>

                        <div class="fm-live-camera__error" x-show="cameraError" x-cloak>
                            <span x-text="cameraError"></span>
                            <button type="button" x-show="! cameraReady" x-on:click="restartCamera()">Muat ulang kamera</button>
                        </div>
                    </footer>
                </div>
            @endif

            <section class="fm-gallery">
                <div class="fm-section-heading">
                    <div>
                        <span class="fm-section-kicker">Penyimpanan server</span>
                        <h2>{{ $activeSession->items_count }} foto di server</h2>
                        <p>Foto terbaru berada di urutan paling awal.</p>
                    </div>
                    <x-filament::icon icon="heroicon-o-photo" />
                </div>

                @if ($activeSession->items->isEmpty())
                    <div class="fm-empty">
                        <x-filament::icon icon="heroicon-o-camera" />
                        <strong>Belum ada foto</strong>
                        <span>Foto pertama yang diambil akan muncul di sini.</span>
                    </div>
                @else
                    <div class="fm-photo-grid">
                        @foreach ($activeSession->items as $item)
                            @php
                                $previewUrl = route('foto-barang.preview', [$activeSession, $item]);
                                $downloadUrl = route('foto-barang.download', [$activeSession, $item]);
                            @endphp

                            <article class="fm-photo-card" wire:key="foto-barang-{{ $item->id }}">
                                <a href="{{ $previewUrl }}" target="_blank" class="fm-photo-card__image">
                                    <img src="{{ $previewUrl }}" alt="Foto barang urutan {{ $item->urutan }}" loading="lazy">
                                    <span>#{{ str_pad((string) $item->urutan, 2, '0', STR_PAD_LEFT) }}</span>
                                </a>

                                <div class="fm-photo-card__body">
                                    <strong>{{ $item->diambil_at->locale('id')->translatedFormat('d M Y, H:i') }} WIB</strong>
                                    @if ($item->processingCompleted())
                                        <span class="fm-processing-status is-completed">Siap dibagikan</span>
                                    @elseif ($item->processingFailed())
                                        <span class="fm-processing-status is-failed">Kompresi gagal, foto sumber tetap aman</span>
                                    @else
                                        <span class="fm-processing-status is-pending">Kompresi latar belakang</span>
                                    @endif
                                    <span>{{ $this->formatBytes($item->ukuran_hasil) }} · {{ $item->lebar }}×{{ $item->tinggi }} px</span>
                                    <small>{{ number_format((float) $item->latitude, 6) }}, {{ number_format((float) $item->longitude, 6) }}</small>
                                </div>

                                <div class="fm-photo-card__actions">
                                    <button
                                        type="button"
                                        x-on:click="sharePhoto(@js($previewUrl), @js($downloadUrl), @js($item->fileName()))"
                                    >
                                        <x-filament::icon icon="heroicon-m-share" /> Bagikan
                                    </button>
                                    <a href="{{ $downloadUrl }}">
                                        <x-filament::icon icon="heroicon-m-arrow-down-tray" /> Unduh
                                    </a>
                                    @if (! $item->processingCompleted())
                                        <button type="button" wire:click="retryPhotoProcessing({{ $item->id }})">
                                            <x-filament::icon icon="heroicon-m-arrow-path" /> Ulangi
                                        </button>
                                    @endif
                                    @if ($activeSession->isActive())
                                        <button
                                            type="button"
                                            class="is-danger"
                                            wire:click="deletePhoto({{ $item->id }})"
                                            wire:confirm="Hapus foto ini dari sesi? File tidak dapat dipulihkan."
                                        >
                                            <x-filament::icon icon="heroicon-m-trash" />
                                        </button>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <section
                class="fm-gallery fm-local-gallery"
                wire:key="foto-local-session-{{ $activeSession->uuid }}"
                x-init="loadLocalGallery(@js($activeSession->uuid))"
                x-show="localGalleryFor === @js($activeSession->uuid) && localCapturedCount > 0"
                x-cloak
            >
                <div class="fm-section-heading">
                    <div>
                        <span class="fm-section-kicker">Penyimpanan perangkat</span>
                        <h2><span x-text="localCapturedCount"></span> foto lokal HP</h2>
                        <p>Foto ini tidak dikirim ke server. Unduh atau bagikan sebelum data browser dibersihkan.</p>
                    </div>
                    <x-filament::icon icon="heroicon-o-device-phone-mobile" />
                </div>

                <div class="fm-local-grid">
                    <template x-for="capture in localCaptures" :key="capture.id">
                        <article class="fm-local-card">
                            <button type="button" class="fm-local-card__preview" x-on:click="previewLocalCapture(capture.id)">
                                <x-filament::icon icon="heroicon-o-photo" />
                                <strong>#<span x-text="String(capture.localSequence || 1).padStart(2, '0')"></span></strong>
                                <small>Pratinjau foto</small>
                            </button>
                            <div class="fm-local-card__info">
                                <strong x-text="localCaptureDate(capture) + ' WIB'"></strong>
                                <span><i></i> Aman di perangkat ini</span>
                            </div>
                            <div class="fm-photo-card__actions">
                                <button type="button" x-on:click="shareLocalCapture(capture.id)">
                                    <x-filament::icon icon="heroicon-m-share" /> Bagikan
                                </button>
                                <button type="button" x-on:click="downloadLocalCapture(capture.id)">
                                    <x-filament::icon icon="heroicon-m-arrow-down-tray" /> Unduh
                                </button>
                                <button type="button" class="is-danger" x-on:click="deleteLocalOnlyCapture(capture.id)" aria-label="Hapus foto lokal">
                                    <x-filament::icon icon="heroicon-m-trash" />
                                </button>
                            </div>
                        </article>
                    </template>
                </div>
            </section>

            <div
                class="fm-local-preview"
                x-show="localPreviewUrl"
                x-cloak
                x-on:click.self="closeLocalPreview()"
                x-on:keydown.escape.window="closeLocalPreview()"
                role="dialog"
                aria-modal="true"
                aria-label="Pratinjau foto lokal"
            >
                <div class="fm-local-preview__panel">
                    <button type="button" class="fm-local-preview__close" x-on:click="closeLocalPreview()" aria-label="Tutup pratinjau">
                        <x-filament::icon icon="heroicon-m-x-mark" />
                    </button>
                    <img x-bind:src="localPreviewUrl" alt="Pratinjau foto lokal">
                    <div>
                        <button type="button" x-on:click="shareLocalCapture(localPreviewCapture.id)">
                            <x-filament::icon icon="heroicon-m-share" /> Bagikan
                        </button>
                        <button type="button" x-on:click="downloadLocalCapture(localPreviewCapture.id)">
                            <x-filament::icon icon="heroicon-m-arrow-down-tray" /> Unduh
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <section class="fm-history">
            <div class="fm-section-heading">
                <div>
                    <span class="fm-section-kicker">Folder tersimpan</span>
                    <h2>Riwayat sesi foto</h2>
                    <p>Sesi terbaru ditampilkan bertahap agar halaman tetap ringan.</p>
                </div>
                <x-filament::icon icon="heroicon-o-folder" />
            </div>

            @if ($sessions->isEmpty())
                <div class="fm-empty fm-empty--small">Belum ada folder sesi foto.</div>
            @else
                <div class="fm-session-list">
                    @foreach ($sessions as $session)
                        <button
                            type="button"
                            wire:click="openSession({{ $session->id }})"
                            @class(['fm-session-row', 'is-current' => $activeSession?->is($session)])
                        >
                            <span class="fm-session-row__icon"><x-filament::icon icon="heroicon-o-folder" /></span>
                            <span class="fm-session-row__main">
                                <strong>{{ $session->judul }}</strong>
                                <small>{{ $session->code() }} · {{ $session->dimulai_at->locale('id')->translatedFormat('d M Y, H:i') }} WIB</small>
                            </span>
                            <span class="fm-session-row__count">{{ $session->items_count }} foto</span>
                            <span @class(['fm-status', 'fm-status--done' => ! $session->isActive()])>
                                {{ $session->isActive() ? 'Aktif' : 'Selesai' }}
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" />
                        </button>
                    @endforeach
                </div>

                @if ($sessions->count() < $this->totalSessions())
                    <button type="button" class="fm-load-more" wire:click="loadMoreSessions">
                        Tampilkan 20 sesi berikutnya
                    </button>
                @endif
            @endif
        </section>
    </div>

    <style>
        .fm-page { --fm-ink:#102033; --fm-muted:#64748b; --fm-line:#dce4ed; --fm-soft:#f5f8fb; display:grid; gap:1.15rem; color:var(--fm-ink); }
        .dark .fm-page { --fm-ink:#edf4fb; --fm-muted:#94a3b8; --fm-line:#29394b; --fm-soft:#101b29; }
        .fm-hero { position:relative; overflow:hidden; display:grid; grid-template-columns:minmax(0,1.15fr) minmax(18rem,.85fr); gap:2rem; align-items:center; padding:1.6rem; border-radius:1.35rem; color:#fff; background:radial-gradient(circle at 86% 12%,rgba(245,158,11,.22),transparent 30%),linear-gradient(140deg,#0f172a,#17365d 60%,#1e3a8a); box-shadow:0 18px 45px rgba(15,23,42,.18); }
        .fm-hero::after { content:""; position:absolute; inset:auto -5rem -8rem auto; width:18rem; height:18rem; border:1px solid rgba(255,255,255,.13); border-radius:50%; }
        .fm-eyebrow,.fm-section-kicker { display:block; color:#fbbf24; font-size:.68rem; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
        .fm-hero h1 { margin:.38rem 0 0; max-width:42rem; font-size:clamp(1.65rem,3vw,2.55rem); line-height:1.08; letter-spacing:-.035em; }
        .fm-hero p { margin:.75rem 0 0; max-width:46rem; color:#d7e2ee; font-size:.87rem; line-height:1.7; }
        .fm-flow { position:relative; z-index:1; display:grid; grid-template-columns:1fr auto 1fr auto 1fr; gap:.55rem; align-items:center; padding:1rem; border:1px solid rgba(255,255,255,.14); border-radius:1rem; background:rgba(8,17,30,.38); backdrop-filter:blur(12px); }
        .fm-flow span { display:grid; justify-items:center; gap:.4rem; color:#e8eef6; font-size:.68rem; font-weight:700; text-align:center; }
        .fm-flow b { display:grid; place-items:center; width:2rem; height:2rem; border-radius:.65rem; color:#182130; background:#fbbf24; }
        .fm-flow i { width:1.2rem; height:1px; background:rgba(255,255,255,.25); }
        .fm-start-card,.fm-capture-card,.fm-template-preview,.fm-gallery,.fm-history,.fm-session-header { border:1px solid var(--fm-line); border-radius:1.15rem; background:var(--fi-body-bg,#fff); box-shadow:0 9px 25px rgba(15,23,42,.05); }
        .dark .fm-start-card,.dark .fm-capture-card,.dark .fm-template-preview,.dark .fm-gallery,.dark .fm-history,.dark .fm-session-header { background:#111c2b; }
        .fm-start-card,.fm-capture-card,.fm-template-preview,.fm-gallery,.fm-history { padding:1.25rem; }
        .fm-section-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; }
        .fm-section-heading--compact { align-items:center; }
        .fm-section-heading h2 { margin:.25rem 0 0; font-size:1.08rem; letter-spacing:-.015em; }
        .fm-section-heading p { margin:.3rem 0 0; color:var(--fm-muted); font-size:.76rem; line-height:1.5; }
        .fm-section-heading>svg { width:2.2rem; height:2.2rem; padding:.5rem; border-radius:.7rem; color:#b77905; background:#fff5d8; }
        .dark .fm-section-heading>svg { color:#fbbf24; background:#2d281c; }
        .fm-form { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; margin-top:1.2rem; }
        .fm-field { display:grid; gap:.4rem; }
        .fm-field--full { grid-column:1/-1; }
        .fm-field>span { font-size:.73rem; font-weight:700; }
        .fm-field input,.fm-field textarea { width:100%; border:1px solid var(--fm-line); border-radius:.75rem; padding:.75rem .85rem; color:var(--fm-ink); background:var(--fm-soft); font-size:.83rem; outline:none; transition:border-color .15s,box-shadow .15s; }
        .fm-field input:focus,.fm-field textarea:focus { border-color:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.14); }
        .fm-field small,.fm-error { color:#dc2626; font-size:.7rem; }
        .fm-form__footer { grid-column:1/-1; display:flex; align-items:center; justify-content:space-between; gap:1rem; padding-top:.35rem; }
        .fm-form__footer p { display:flex; align-items:center; gap:.4rem; margin:0; color:var(--fm-muted); font-size:.72rem; }
        .fm-form__footer svg { width:1rem; }
        .fm-session-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1.15rem 1.25rem; }
        .fm-session-header__meta { display:flex; align-items:center; gap:.65rem; color:var(--fm-muted); font-size:.67rem; font-weight:700; }
        .fm-session-header h2 { margin:.35rem 0 .1rem; font-size:1.18rem; }
        .fm-session-header p { margin:0; color:var(--fm-muted); font-size:.76rem; }
        .fm-status { display:inline-flex; align-items:center; width:max-content; padding:.3rem .55rem; border-radius:999px; color:#166534; background:#dcfce7; font-size:.62rem; font-weight:800; }
        .fm-status--done { color:#475569; background:#e2e8f0; }
        .dark .fm-status { color:#86efac; background:#153625; }
        .dark .fm-status--done { color:#cbd5e1; background:#2a394b; }
        .fm-session-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:.55rem; }
        .fm-background-queue { display:flex; align-items:center; gap:.5rem; padding:.68rem .8rem; border:1px solid #bfdbfe; border-radius:.75rem; color:#1e3a8a; background:#eff6ff; font-size:.68rem; font-weight:700; }
        .dark .fm-background-queue { border-color:#28496d; color:#bfdbfe; background:#142439; }
        .fm-background-queue svg { flex:0 0 auto; width:1rem; }
        .fm-background-queue b { margin-left:auto; }
        .fm-background-queue button { padding:.27rem .5rem; border:1px solid currentColor; border-radius:999px; color:inherit; background:transparent; font-size:.6rem; font-weight:800; }
        .fm-workspace { display:grid; grid-template-columns:minmax(0,1.05fr) minmax(19rem,.95fr); gap:1.15rem; }
        .fm-counter { display:grid; place-items:center; min-width:2.3rem; height:2.3rem; border-radius:.75rem; color:#92400e; background:#fef3c7; font-size:.84rem; font-weight:850; }
        .fm-gps { display:grid; grid-template-columns:auto minmax(0,1fr) auto; gap:.75rem; align-items:center; margin-top:1rem; padding:.8rem; border:1px solid #f7c7c7; border-radius:.85rem; background:#fff7f7; }
        .fm-gps--ready { border-color:#b7e3c5; background:#f2fbf5; }
        .dark .fm-gps { border-color:#63353a; background:#2a1c24; }
        .dark .fm-gps--ready { border-color:#28563b; background:#172a22; }
        .fm-gps__icon { display:grid; place-items:center; width:2.2rem; height:2.2rem; border-radius:.65rem; color:#dc2626; background:#fee2e2; }
        .fm-gps--ready .fm-gps__icon { color:#15803d; background:#dcfce7; }
        .fm-gps__icon svg { width:1.15rem; }
        .fm-gps>div:nth-child(2) { display:grid; gap:.15rem; }
        .fm-gps strong { font-size:.73rem; }
        .fm-gps span { color:var(--fm-muted); font-size:.64rem; }
        .fm-gps button,.fm-location-fallback button { border:0; color:#9a6700; background:transparent; font-size:.67rem; font-weight:800; cursor:pointer; }
        .fm-location-fallback { display:flex; justify-content:space-between; gap:.75rem; margin-top:.65rem; padding:.7rem .8rem; border-radius:.7rem; background:var(--fm-soft); }
        .fm-location-fallback p { margin:0; color:var(--fm-muted); font-size:.65rem; line-height:1.45; }
        .fm-location-fallback button { flex:0 0 auto; }
        .fm-mode-picker { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.65rem; margin-top:1rem; }
        .fm-mode-picker>button { display:grid; grid-template-columns:auto minmax(0,1fr); gap:.12rem .65rem; align-items:center; padding:.75rem; border:1px solid var(--fm-line); border-radius:.8rem; color:var(--fm-ink); background:var(--fm-soft); text-align:left; cursor:pointer; transition:border-color .15s,box-shadow .15s,background .15s; }
        .fm-mode-picker>button.is-active { border-color:#f59e0b; background:#fff8e6; box-shadow:0 0 0 3px rgba(245,158,11,.12); }
        .dark .fm-mode-picker>button.is-active { background:#302719; }
        .fm-mode-picker>button>span { grid-row:1/3; display:grid; place-items:center; width:2.25rem; height:2.25rem; border-radius:.65rem; color:#64748b; background:var(--fi-body-bg,#fff); }
        .fm-mode-picker>button.is-active>span { color:#b45309; }
        .fm-mode-picker svg { width:1.15rem; }
        .fm-mode-picker strong { font-size:.7rem; }
        .fm-mode-picker small { color:var(--fm-muted); font-size:.57rem; line-height:1.35; }
        .fm-local-warning { display:flex; gap:.5rem; margin-top:.65rem; padding:.65rem .75rem; border:1px solid #fde68a; border-radius:.7rem; color:#854d0e; background:#fffbeb; }
        .dark .fm-local-warning { border-color:#58441b; color:#fde68a; background:#2c2517; }
        .fm-local-warning svg { flex:0 0 auto; width:1rem; }
        .fm-local-warning p { margin:0; font-size:.62rem; line-height:1.45; }
        .fm-open-camera { display:grid; grid-template-columns:auto minmax(0,1fr) auto; gap:.8rem; align-items:center; width:100%; margin-top:1rem; padding:.9rem; border:0; border-radius:.9rem; color:#fff; background:linear-gradient(135deg,#d97706,#f59e0b); box-shadow:0 12px 24px rgba(217,119,6,.22); text-align:left; cursor:pointer; }
        .fm-open-camera>span:first-child { display:grid; place-items:center; width:2.7rem; height:2.7rem; border-radius:.75rem; background:rgba(255,255,255,.18); }
        .fm-open-camera>span:nth-child(2) { display:grid; gap:.1rem; }
        .fm-open-camera svg { width:1.3rem; }
        .fm-open-camera>svg { width:1rem; }
        .fm-open-camera strong { font-size:.82rem; }
        .fm-open-camera small { color:#fff7d6; font-size:.65rem; }
        .fm-camera-error { margin:.65rem 0 0; padding:.65rem .75rem; border-radius:.65rem; color:#991b1b; background:#fee2e2; font-size:.68rem; }
        .fm-camera-box { position:relative; overflow:hidden; min-height:12rem; margin-top:1rem; border:1.5px dashed #b7c4d2; border-radius:1rem; background:linear-gradient(145deg,var(--fm-soft),rgba(245,158,11,.06)); }
        .fm-camera-box>input { position:absolute; width:1px; height:1px; opacity:0; pointer-events:none; }
        .fm-camera-button { display:grid; place-items:center; align-content:center; min-height:12rem; padding:1.2rem; cursor:pointer; text-align:center; }
        .fm-camera-button>span { display:grid; place-items:center; width:4.2rem; height:4.2rem; margin-bottom:.75rem; border-radius:1.25rem; color:#fff; background:linear-gradient(145deg,#f59e0b,#d97706); box-shadow:0 10px 26px rgba(217,119,6,.28); }
        .fm-camera-button svg { width:2rem; }
        .fm-camera-button strong { font-size:.94rem; }
        .fm-camera-button small { margin-top:.25rem; color:var(--fm-muted); font-size:.67rem; }
        .fm-processing { position:absolute; inset:0; z-index:4; display:none; flex-direction:column; align-items:center; justify-content:center; gap:.35rem; color:#fff; background:rgba(9,18,30,.9); backdrop-filter:blur(8px); }
        .fm-processing strong { margin-top:.4rem; font-size:.84rem; }
        .fm-processing small { color:#cbd5e1; font-size:.65rem; }
        .fm-spinner { width:2.2rem; height:2.2rem; border:3px solid rgba(255,255,255,.25); border-top-color:#fbbf24; border-radius:50%; animation:fm-spin .75s linear infinite; }
        @keyframes fm-spin { to { transform:rotate(360deg); } }
        .fm-pending-photo { display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin-top:.8rem; padding:.75rem; border-radius:.75rem; background:#fff7ed; }
        .dark .fm-pending-photo { background:#30251a; }
        .fm-pending-photo p { margin:0; color:#9a5c0a; font-size:.7rem; }
        .fm-capture-note { display:flex; gap:.55rem; margin-top:.9rem; color:var(--fm-muted); }
        .fm-capture-note svg { flex:0 0 auto; width:1rem; color:#d68d05; }
        .fm-capture-note p { margin:0; font-size:.67rem; line-height:1.5; }
        .fm-template-frame { overflow:hidden; margin-top:1rem; border-radius:1rem; background:#17212d; box-shadow:0 16px 30px rgba(15,23,42,.15); }
        .fm-template-image { display:grid; place-items:center; align-content:center; aspect-ratio:4/3; color:#7590aa; background:radial-gradient(circle at 30% 30%,#35516b,#1b2c3e 65%,#12202e); }
        .fm-template-image svg { width:3.3rem; }
        .fm-template-image span { margin-top:.5rem; font-size:.7rem; }
        .fm-template-overlay { padding:.8rem .9rem 1rem; color:#fff; background:linear-gradient(135deg,#070b11,#101923); }
        .fm-template-overlay>small { display:block; color:#fbbf24; font-size:.52rem; font-weight:800; text-align:right; }
        .fm-template-overlay>div { display:grid; grid-template-columns:auto 3px 1fr; gap:.65rem; align-items:center; margin-top:.45rem; }
        .fm-template-overlay strong { font-size:1.35rem; }
        .fm-template-overlay i { width:3px; height:2.5rem; background:#f59e0b; }
        .fm-template-overlay b { font-size:.73rem; line-height:1.45; }
        .fm-template-overlay h3 { margin:.55rem 0 .18rem; font-size:.73rem; }
        .fm-template-overlay p { display:-webkit-box; overflow:hidden; margin:0; color:#d8e0e9; font-size:.52rem; line-height:1.4; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .fm-template-overlay>span { display:block; margin-top:.3rem; color:#cbd5e1; font-size:.5rem; }
        .fm-gallery,.fm-history { display:grid; gap:1rem; }
        .fm-local-gallery { border-color:#f6d88d; background:linear-gradient(145deg,var(--fi-body-bg,#fff),#fffbeb); }
        .dark .fm-local-gallery { border-color:#51411e; background:linear-gradient(145deg,#111c2b,#211d16); }
        .fm-local-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.9rem; }
        .fm-local-card { overflow:hidden; border:1px solid var(--fm-line); border-radius:.9rem; background:var(--fi-body-bg,#fff); }
        .dark .fm-local-card { background:#142132; }
        .fm-local-card__preview { display:grid; place-items:center; align-content:center; width:100%; min-height:9rem; border:0; color:#d7920b; background:radial-gradient(circle at 50% 30%,#fff7db,#f3f6f9 70%); cursor:pointer; }
        .dark .fm-local-card__preview { color:#fbbf24; background:radial-gradient(circle at 50% 30%,#342c1b,#101b29 70%); }
        .fm-local-card__preview svg { width:2.2rem; opacity:.8; }
        .fm-local-card__preview strong { margin-top:.35rem; color:var(--fm-ink); font-size:.75rem; }
        .fm-local-card__preview small { margin-top:.15rem; color:var(--fm-muted); font-size:.58rem; }
        .fm-local-card__info { display:grid; gap:.25rem; padding:.7rem; }
        .fm-local-card__info strong { font-size:.67rem; }
        .fm-local-card__info span { display:flex; align-items:center; gap:.3rem; color:#15803d; font-size:.58rem; font-weight:750; }
        .fm-local-card__info i { width:.42rem; height:.42rem; border-radius:50%; background:#22c55e; }
        .fm-local-preview[x-cloak] { display:none!important; }
        .fm-local-preview { position:fixed; z-index:10020; inset:0; display:grid; place-items:center; padding:1rem; background:rgba(2,6,12,.9); }
        .fm-local-preview__panel { position:relative; display:grid; gap:.75rem; max-width:46rem; max-height:calc(100dvh - 2rem); width:100%; padding:.7rem; border-radius:1rem; background:#0b111b; box-shadow:0 20px 60px rgba(0,0,0,.45); }
        .fm-local-preview__panel>img { width:100%; max-height:calc(100dvh - 7rem); border-radius:.65rem; object-fit:contain; background:#000; }
        .fm-local-preview__close { position:absolute; z-index:2; top:1rem; right:1rem; display:grid; place-items:center; width:2.35rem; height:2.35rem; border:0; border-radius:50%; color:#fff; background:rgba(2,6,12,.78); cursor:pointer; }
        .fm-local-preview__close svg { width:1.15rem; }
        .fm-local-preview__panel>div { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.6rem; }
        .fm-local-preview__panel>div button { display:flex; align-items:center; justify-content:center; gap:.35rem; min-height:2.5rem; border:1px solid #334155; border-radius:.65rem; color:#fff; background:#172033; font-size:.7rem; font-weight:800; }
        .fm-local-preview__panel>div svg { width:1rem; }
        .fm-empty { display:grid; justify-items:center; gap:.35rem; padding:2.4rem 1rem; border:1px dashed var(--fm-line); border-radius:.9rem; color:var(--fm-muted); text-align:center; }
        .fm-empty svg { width:2.5rem; opacity:.55; }
        .fm-empty strong { color:var(--fm-ink); font-size:.8rem; }
        .fm-empty span,.fm-empty--small { font-size:.68rem; }
        .fm-empty--small { padding:1.2rem; }
        .fm-photo-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.9rem; }
        .fm-photo-card { overflow:hidden; border:1px solid var(--fm-line); border-radius:.9rem; background:var(--fm-soft); }
        .fm-photo-card__image { position:relative; display:block; aspect-ratio:4/5; overflow:hidden; background:#0f172a; }
        .fm-photo-card__image img { width:100%; height:100%; object-fit:contain; }
        .fm-photo-card__image>span { position:absolute; top:.55rem; left:.55rem; padding:.3rem .45rem; border-radius:.5rem; color:#1f1708; background:#fbbf24; font-size:.62rem; font-weight:850; }
        .fm-photo-card__body { display:grid; gap:.15rem; padding:.75rem; }
        .fm-photo-card__body strong { font-size:.7rem; }
        .fm-photo-card__body span,.fm-photo-card__body small { color:var(--fm-muted); font-size:.6rem; }
        .fm-photo-card__body .fm-processing-status { width:max-content; max-width:100%; margin:.12rem 0; padding:.22rem .42rem; border-radius:999px; font-size:.56rem; font-weight:800; }
        .fm-processing-status.is-completed { color:#166534; background:#dcfce7; }
        .fm-processing-status.is-pending { color:#92400e; background:#fef3c7; }
        .fm-processing-status.is-failed { color:#991b1b; background:#fee2e2; }
        .dark .fm-processing-status.is-completed { color:#86efac; background:#153625; }
        .dark .fm-processing-status.is-pending { color:#fcd34d; background:#3b2c13; }
        .dark .fm-processing-status.is-failed { color:#fca5a5; background:#3a1b22; }
        .fm-photo-card__actions { display:flex; gap:.35rem; padding:0 .65rem .7rem; }
        .fm-photo-card__actions button,.fm-photo-card__actions a { display:flex; flex:1; align-items:center; justify-content:center; gap:.25rem; min-height:2rem; border:1px solid var(--fm-line); border-radius:.55rem; color:var(--fm-ink); background:var(--fi-body-bg,#fff); font-size:.62rem; font-weight:750; text-decoration:none; cursor:pointer; }
        .dark .fm-photo-card__actions button,.dark .fm-photo-card__actions a { background:#172436; }
        .fm-photo-card__actions svg { width:.85rem; }
        .fm-photo-card__actions .is-danger { flex:0 0 2rem; color:#dc2626; }
        .fm-session-list { display:grid; overflow:hidden; border:1px solid var(--fm-line); border-radius:.9rem; }
        .fm-session-row { display:grid; grid-template-columns:auto minmax(0,1fr) auto auto auto; gap:.75rem; align-items:center; width:100%; padding:.8rem; border:0; border-bottom:1px solid var(--fm-line); color:var(--fm-ink); background:transparent; text-align:left; cursor:pointer; }
        .fm-session-row:last-child { border-bottom:0; }
        .fm-session-row:hover,.fm-session-row.is-current { background:var(--fm-soft); }
        .fm-session-row__icon { display:grid; place-items:center; width:2.2rem; height:2.2rem; border-radius:.65rem; color:#b77905; background:#fff3cf; }
        .dark .fm-session-row__icon { color:#fbbf24; background:#30291b; }
        .fm-session-row__icon svg,.fm-session-row>svg { width:1.05rem; }
        .fm-session-row__main { display:grid; gap:.15rem; min-width:0; }
        .fm-session-row__main strong { overflow:hidden; font-size:.72rem; text-overflow:ellipsis; white-space:nowrap; }
        .fm-session-row__main small,.fm-session-row__count { color:var(--fm-muted); font-size:.61rem; }
        .fm-load-more { justify-self:center; padding:.65rem 1rem; border:1px solid var(--fm-line); border-radius:.65rem; color:var(--fm-ink); background:var(--fm-soft); font-size:.68rem; font-weight:750; cursor:pointer; }
        .fm-live-camera[x-cloak] { display:none!important; }
        .fm-live-camera { position:fixed; z-index:9999; inset:0; display:grid; grid-template-rows:auto minmax(0,1fr) auto; color:#fff; background:#020408; font-family:"Roboto Condensed","Arial Narrow",Roboto,Arial,sans-serif; }
        .fm-live-camera__header { display:grid; grid-template-columns:2.7rem minmax(0,1fr) 2.7rem; gap:.7rem; align-items:center; padding:calc(.55rem + env(safe-area-inset-top)) .75rem .55rem; background:#080d14; }
        .fm-live-camera__header>button { display:grid; place-items:center; width:2.7rem; height:2.7rem; border:0; border-radius:999px; color:#fff; background:#1b2635; cursor:pointer; }
        .fm-live-camera__header>button:disabled { opacity:.45; cursor:not-allowed; }
        .fm-live-camera__header svg { width:1.25rem; }
        .fm-live-camera__header svg.is-spinning { animation:fm-spin .75s linear infinite; }
        .fm-live-camera__header>div { display:grid; min-width:0; text-align:center; }
        .fm-live-camera__header strong { overflow:hidden; font-size:.8rem; text-overflow:ellipsis; white-space:nowrap; }
        .fm-live-camera__header span { color:#aab7c7; font-size:.65rem; }
        .fm-live-camera__stage { position:relative; min-height:0; overflow:hidden; background:#000; }
        .fm-live-camera__stage>video { width:100%; height:100%; object-fit:contain; background:#000; }
        .fm-live-camera__shade { position:absolute; inset:0; pointer-events:none; background:linear-gradient(to bottom,rgba(0,0,0,.12),transparent 24%,transparent 58%,rgba(0,0,0,.35)); }
        .fm-live-camera__badge { position:absolute; z-index:2; right:0; bottom:calc(100% + .4rem); display:flex; align-items:center; gap:.38rem; padding:.42rem .58rem; border-radius:.15rem; color:#fff; background:rgba(2,7,13,.72); font-size:clamp(.63rem,2.5vw,.9rem); font-weight:800; letter-spacing:.015em; text-shadow:0 1px 2px #000; }
        .fm-live-camera__badge i { width:.52rem; height:.52rem; border-radius:50%; background:#fbbf24; box-shadow:0 0 0 2px rgba(255,255,255,.35); }
        .fm-live-camera__watermark { position:absolute; z-index:2; right:.55rem; bottom:.35rem; left:.55rem; padding:clamp(.72rem,2.4vw,1.25rem); color:#fff; background:rgba(2,7,13,.76); text-shadow:0 1px 3px rgba(0,0,0,.95); }
        .fm-live-camera__datetime { display:grid; grid-template-columns:auto .24rem minmax(0,1fr); gap:clamp(.65rem,3vw,1.15rem); align-items:center; }
        .fm-live-camera__datetime>strong { font-size:clamp(2.05rem,9.2vw,5.7rem); font-weight:800; line-height:.95; letter-spacing:-.04em; white-space:nowrap; }
        .fm-live-camera__datetime>i { width:.24rem; height:clamp(3.15rem,12vw,6rem); background:#f7b500; }
        .fm-live-camera__datetime>b { font-size:clamp(1.15rem,5vw,3rem); font-weight:800; line-height:1.02; }
        .fm-live-camera__watermark h3 { display:flex; align-items:center; gap:.35rem; overflow:hidden; margin:clamp(.45rem,1.7vw,.8rem) 0 .12rem; font-size:clamp(1rem,4.5vw,2.6rem); font-weight:800; line-height:1.05; text-overflow:ellipsis; white-space:nowrap; }
        .fm-live-camera__watermark h3 span { font-size:.85em; }
        .fm-live-camera__watermark p { display:-webkit-box; overflow:hidden; margin:0; color:#f3f5f8; font-size:clamp(.77rem,3.15vw,1.65rem); font-weight:600; line-height:1.18; -webkit-box-orient:vertical; -webkit-line-clamp:2; }
        .fm-live-camera__watermark>small { display:block; overflow:hidden; margin-top:.22rem; color:#e6ebf1; font-size:clamp(.68rem,2.75vw,1.4rem); font-weight:600; line-height:1.1; text-overflow:ellipsis; white-space:nowrap; }
        .fm-live-camera__flash { position:absolute; z-index:5; inset:0; pointer-events:none; background:#fff; opacity:0; transition:opacity .14s ease-out; }
        .fm-live-camera__flash.is-visible { opacity:.72; transition:none; }
        .fm-live-camera__controls { display:grid; gap:.55rem; padding:.55rem .8rem calc(.65rem + env(safe-area-inset-bottom)); background:#080d14; }
        .fm-live-camera__gps { display:flex; align-items:center; justify-content:center; gap:.35rem; min-height:1.4rem; color:#fbbf24; font-size:.64rem; font-weight:700; text-align:center; }
        .fm-live-camera__gps.is-ready { color:#86efac; }
        .fm-live-camera__gps svg { flex:0 0 auto; width:.85rem; }
        .fm-live-camera__gps button { margin-left:.2rem; padding:.22rem .45rem; border:1px solid rgba(255,255,255,.25); border-radius:999px; color:#fff; background:transparent; font-size:.58rem; font-weight:800; }
        .fm-live-camera__queue { display:flex; align-items:center; justify-content:center; gap:.4rem; min-height:1.35rem; color:#bfdbfe; font-size:.61rem; font-weight:700; text-align:center; }
        .fm-live-camera__queue svg { flex:0 0 auto; width:.9rem; }
        .fm-live-camera__queue b { color:#fff; font-size:.59rem; }
        .fm-live-camera__queue button { padding:.2rem .42rem; border:1px solid #52657b; border-radius:999px; color:#fff; background:#1b2635; font-size:.56rem; font-weight:800; }
        .fm-live-camera__queue.is-local { color:#86efac; }
        .fm-live-camera__actions { display:grid; grid-template-columns:minmax(4.5rem,1fr) 5rem minmax(4.5rem,1fr); gap:1rem; align-items:center; max-width:30rem; width:100%; margin:auto; }
        .fm-live-camera__finish { justify-self:start; padding:.52rem .72rem; border:1px solid #465466; border-radius:999px; color:#fff; background:#1b2635; font-size:.68rem; font-weight:800; }
        .fm-live-camera__shutter { display:grid; place-items:center; width:4.8rem; height:4.8rem; padding:.3rem; border:3px solid #fff; border-radius:50%; background:transparent; cursor:pointer; }
        .fm-live-camera__shutter span { width:100%; height:100%; border-radius:50%; background:#fff; transition:transform .1s,background .1s; }
        .fm-live-camera__shutter:active span { transform:scale(.88); background:#fbbf24; }
        .fm-live-camera__shutter:disabled { opacity:.45; cursor:not-allowed; }
        .fm-live-camera__sequence { display:grid; justify-self:end; text-align:center; }
        .fm-live-camera__sequence strong { font-size:.82rem; }
        .fm-live-camera__sequence small { color:#9dadc0; font-size:.58rem; }
        .fm-live-camera__error { display:flex; align-items:center; justify-content:center; gap:.55rem; margin:0; padding:.42rem .6rem; border-radius:.5rem; color:#fecaca; background:#35151b; font-size:.64rem; text-align:center; }
        .fm-live-camera__error button { flex:0 0 auto; padding:.28rem .5rem; border:1px solid rgba(255,255,255,.28); border-radius:999px; color:#fff; background:transparent; font-size:.58rem; font-weight:800; }
        @media(max-width:900px) { .fm-hero,.fm-workspace { grid-template-columns:1fr; } .fm-template-preview { max-width:34rem; width:100%; justify-self:center; } }
        @media(max-width:640px) {
            .fm-page { gap:.8rem; }
            .fm-hero { gap:1.15rem; padding:1.15rem; border-radius:1rem; }
            .fm-flow { gap:.3rem; padding:.75rem .5rem; }
            .fm-flow span { font-size:.57rem; }
            .fm-flow b { width:1.7rem; height:1.7rem; }
            .fm-start-card,.fm-capture-card,.fm-template-preview,.fm-gallery,.fm-history { padding:.9rem; border-radius:.9rem; }
            .fm-session-header { align-items:flex-start; flex-direction:column; padding:.9rem; }
            .fm-session-actions { width:100%; justify-content:flex-start; }
            .fm-form { grid-template-columns:1fr; }
            .fm-form__footer { align-items:stretch; flex-direction:column; }
            .fm-gps { grid-template-columns:auto minmax(0,1fr); }
            .fm-gps>button { grid-column:1/-1; justify-self:start; }
            .fm-location-fallback { align-items:flex-start; flex-direction:column; }
            .fm-mode-picker { grid-template-columns:1fr; }
            .fm-photo-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.6rem; }
            .fm-local-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.6rem; }
            .fm-session-row { grid-template-columns:auto minmax(0,1fr) auto; gap:.55rem; }
            .fm-session-row__count { display:none; }
            .fm-session-row .fm-status { grid-column:2; }
            .fm-session-row>svg { grid-column:3; grid-row:1/3; }
        }
        @media(max-width:410px) { .fm-photo-grid,.fm-local-grid { grid-template-columns:1fr; } }
        @media(prefers-reduced-motion:reduce) { .fm-spinner { animation-duration:1.5s; } }
    </style>
</x-filament-panels::page>
