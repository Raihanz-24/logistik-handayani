<x-filament-panels::page>
    @vite('resources/js/foto-barang-maps.js')

    @php
        $activeSession = $this->activeSession();
        $sessions = $this->sessions();
        $cameraConfig = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $accuracy,
            'verticalCropRatio' => (float) config('foto_barang.vertical_crop_ratio', 0.045),
            'sessionLocation' => $activeSession?->nama_lokasi ?? '',
            'sessionAddress' => $activeSession?->alamat ?? '',
            'capturedCount' => (int) ($activeSession?->items_count ?? 0),
            'serverCapturedCount' => (int) ($activeSession?->items_count ?? 0),
            'sessionUuid' => $activeSession?->uuid,
            'sessionIsActive' => (bool) ($activeSession?->isActive() ?? false),
            'uploadUrlTemplate' => route('foto-barang.upload', ['session' => '__SESSION_UUID__'], absolute: false),
            'selectedArchiveUrlTemplate' => route('foto-barang.selected-archive', ['session' => '__SESSION_UUID__'], absolute: false),
        ];
    @endphp

    <div
        class="fm-page"
        x-data="fotoBarangMaps()"
        x-init="initializeCamera(JSON.parse($refs.cameraConfig.textContent)); initCamera()"
        x-on:foto-barang-saved.window="handlePhotoSaved()"
        x-on:foto-barang-failed.window="handlePhotoFailed()"
        x-on:foto-barang-deleted.window="serverCapturedCount = Math.max(0, serverCapturedCount - 1); capturedCount = Math.max(0, capturedCount - 1)"
        x-on:online.window="retryPendingUploads()"
    >
        <script type="application/json" x-ref="cameraConfig">@json($cameraConfig)</script>

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
                <span><b>3</b> Selesai & bagikan</span>
            </div>
        </section>

        @if (! $activeSession)
            <section class="fm-start-card">
                <div class="fm-section-heading">
                    <div>
                        <span class="fm-section-kicker">Folder baru</span>
                        <h2>Mulai sesi foto barang</h2>
                        <p>Cukup beri nama sesi. Lokasi, alamat lengkap, dan kode pos diambil otomatis dari GPS saat kamera dibuka.</p>
                    </div>
                    <x-filament::icon icon="heroicon-o-folder-plus" />
                </div>

                <form wire:submit="startSession" class="fm-form">
                    <label class="fm-field fm-field--full">
                        <span>Nama sesi</span>
                        <input type="text" wire:model="judul" maxlength="150" placeholder="Contoh: Barang datang supplier dapur">
                        @error('judul') <small>{{ $message }}</small> @enderror
                    </label>

                    <div class="fm-form__footer">
                        <p><x-filament::icon icon="heroicon-o-map-pin" /> Alamat otomatis dari GPS · © OpenStreetMap contributors.</p>
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
                        <span x-text="sessionLocation || @js($activeSession->nama_lokasi)"></span> · {{ $activeSession->items_count }} foto server
                        <span x-show="localGalleryFor === @js($activeSession->uuid) && localCapturedCount > 0" x-cloak>
                            · <b x-text="localCapturedCount"></b> foto lokal HP
                        </span>
                    </p>
                </div>

                <div class="fm-session-actions">
                    <button
                        type="button"
                        class="fm-share-all-button"
                        x-show="localCapturedCount > 0"
                        x-on:click="shareAllSessionPhotos(
                            @js(route('foto-barang.archive', $activeSession)),
                            @js('foto-maps-'.$activeSession->code().'.zip'),
                            @js($activeSession->judul),
                        )"
                        x-bind:disabled="shareAllBusy || uploadInProgress || queuedCount > 0"
                        x-cloak
                    >
                        <x-filament::icon icon="heroicon-m-share" />
                        <span x-text="shareAllBusy ? `Menyiapkan ${shareAllProgress}%` : 'Bagikan Foto Lokal'"></span>
                    </button>

                    <x-filament::button
                        tag="a"
                        :href="\App\Filament\Pages\FotoBarangFolder::getUrl(['session' => $activeSession->uuid])"
                        color="gray"
                        icon="heroicon-m-folder-open"
                    >
                        Buka Folder ({{ $activeSession->items_count }})
                    </x-filament::button>

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

            <div class="fm-recovery" x-show="recoveryAvailable" x-cloak>
                <span><x-filament::icon icon="heroicon-m-arrow-path-rounded-square" /></span>
                <div>
                    <strong>Sesi sebelumnya dipulihkan</strong>
                    <small x-text="recoveryMessage"></small>
                </div>
                <button type="button" x-on:click="resumeRecoveredSession()">Lanjutkan Kamera</button>
                <button type="button" class="is-muted" x-on:click="dismissRecovery()">Tutup</button>
            </div>

            <div class="fm-share-status" x-show="shareAllStatus" x-cloak>
                <x-filament::icon icon="heroicon-m-information-circle" />
                <span x-text="shareAllStatus"></span>
            </div>

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
                                <span x-text="latitude !== null && longitude !== null ? `${Number(latitude).toFixed(6)}, ${Number(longitude).toFixed(6)}` : 'Koordinat akan dicetak pada foto'"></span>
                                <small x-show="sessionAddress" x-text="sessionAddress" x-cloak></small>
                            </div>
                            <div class="fm-gps__actions">
                                <button type="button" x-on:click="refreshGps(true)" x-bind:disabled="locating || resolvingAddress || templateApplying">Refresh GPS</button>
                                <button
                                    type="button"
                                    class="fm-gps__template"
                                    x-on:click="useHandayaniTemplateLocation()"
                                    x-bind:disabled="templateApplying"
                                    x-bind:class="locationMode === 'template' && 'is-active'"
                                >Template Handayani</button>
                            </div>
                        </div>

                        <div class="fm-mode-picker" role="group" aria-label="Pilih penyimpanan foto">
                            <button
                                type="button"
                                x-on:click="setCaptureMode('server')"
                                x-bind:class="captureMode === 'server' && 'is-active'"
                            >
                                <span><x-filament::icon icon="heroicon-o-cloud-arrow-up" /></span>
                                <strong>Mode Server</strong>
                                <small>Aman di server dan bisa dibuka dari perangkat lain</small>
                            </button>
                            <button
                                type="button"
                                x-on:click="setCaptureMode('local')"
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
                            x-bind:disabled="refreshInProgress"
                        >
                            <span><x-filament::icon icon="heroicon-o-camera" /></span>
                            <span>
                                <strong>Buka Kamera Berkelanjutan</strong>
                                <small>Foto banyak barang tanpa keluar dari kamera</small>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" />
                        </button>

                        <p class="fm-camera-error" x-show="cameraError && ! cameraOpen" x-text="cameraError" x-cloak></p>

                        @error('photo') <p class="fm-error">{{ $message }}</p> @enderror
                        @error('latitude') <p class="fm-error">{{ $message }}</p> @enderror

                        <div class="fm-capture-note">
                            <x-filament::icon icon="heroicon-o-bolt" />
                            <p>Setelah satu foto selesai tersimpan, tombol kamera langsung siap untuk barang berikutnya.</p>
                        </div>
                    </section>

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
                        <button type="button" x-on:click="refreshGps(true)" x-bind:disabled="locating || captureBusy || templateApplying" aria-label="Refresh GPS">
                            <x-filament::icon icon="heroicon-m-arrow-path" x-bind:class="locating && 'is-spinning'" />
                        </button>
                    </header>

                    <main class="fm-live-camera__stage">
                        <video x-ref="cameraVideo" autoplay playsinline muted></video>
                        <canvas x-ref="captureCanvas" hidden></canvas>
                        <div class="fm-live-camera__shade"></div>
                        <div class="fm-live-camera__crop-mask" aria-hidden="true"></div>

                        <div class="fm-live-camera__watermark" aria-hidden="true">
                            <div class="fm-live-camera__badge"><i></i> HANDAYANI MAP CAMERA</div>
                            <div class="fm-live-camera__datetime">
                                <strong><span x-text="liveTime"></span> WIB</strong>
                                <i></i>
                                <b><span x-text="liveDate"></span><br><span x-text="liveDay"></span></b>
                            </div>
                            <h3><b x-text="sessionLocation || @js($activeSession->nama_lokasi)"></b> <span>🇮🇩</span></h3>
                            <p x-text="sessionAddress || @js($activeSession->alamat)"></p>
                            <small>
                                Lat <span x-text="latitude === null ? '-' : Number(latitude).toFixed(6)"></span>
                                &nbsp; Long <span x-text="longitude === null ? '-' : Number(longitude).toFixed(6)"></span>
                            </small>
                        </div>

                        <div class="fm-live-camera__flash" x-ref="cameraFlash"></div>
                    </main>

                    <footer class="fm-live-camera__controls">
                        <div class="fm-live-camera__preferences">
                            <button
                                type="button"
                                x-on:click="toggleShutterBeep()"
                                x-bind:class="beepEnabled && 'is-active'"
                                x-bind:aria-pressed="beepEnabled"
                            >
                                <x-filament::icon icon="heroicon-m-speaker-wave" x-show="beepEnabled" />
                                <x-filament::icon icon="heroicon-m-speaker-x-mark" x-show="! beepEnabled" />
                                <span x-text="beepEnabled ? 'Beep Aktif' : 'Beep Nonaktif'"></span>
                            </button>
                            <button
                                type="button"
                                x-on:click="toggleTorch()"
                                x-bind:class="torchEnabled && 'is-active'"
                                x-bind:aria-pressed="torchEnabled"
                                x-bind:disabled="! torchSupported || torchBusy"
                                x-bind:title="torchSupported ? 'Hidupkan atau matikan flash kamera' : 'Flash tidak didukung perangkat ini'"
                            >
                                <x-filament::icon icon="heroicon-m-bolt" />
                                <span x-text="torchBusy ? 'Mengatur Flash...' : (torchSupported ? (torchEnabled ? 'Flash Aktif' : 'Flash Nonaktif') : 'Flash Tidak Tersedia')"></span>
                            </button>
                        </div>

                        <div class="fm-live-camera__gps" x-bind:class="gpsReady ? 'is-ready' : 'is-warning'">
                            <x-filament::icon icon="heroicon-m-map-pin" />
                            <span x-text="gpsState"></span>
                            <button
                                type="button"
                                x-show="locationMode !== 'template'"
                                x-on:click="useHandayaniTemplateLocation()"
                                x-bind:disabled="templateApplying || captureBusy"
                                x-cloak
                            >Pakai Template</button>
                            <button
                                type="button"
                                x-show="locationMode === 'template'"
                                x-on:click="refreshGps(true)"
                                x-bind:disabled="locating || captureBusy"
                                x-cloak
                            >Pakai GPS</button>
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
                            <button type="button" class="fm-live-camera__finish" x-on:click="closeCameraAndRefresh()" x-bind:disabled="captureBusy">
                                Keluar Kamera
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
                                <button type="button" class="is-danger" x-on:click="requestDeleteLocalPhoto(capture.id)" aria-label="Hapus foto lokal">
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
                        <button type="button" class="is-danger" x-on:click="requestDeleteLocalPhoto(localPreviewCapture.id)">
                            <x-filament::icon icon="heroicon-m-trash" /> Hapus
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
                    <p>
                        @if ($historyDate !== '')
                            Menampilkan folder tanggal {{ \Carbon\CarbonImmutable::parse($historyDate)->locale('id')->translatedFormat('d F Y') }}.
                        @else
                            Menampilkan seluruh tanggal, terbaru lebih dahulu.
                        @endif
                    </p>
                </div>
                <button type="button" class="fm-history-filter-button" x-on:click="historyFiltersOpen = ! historyFiltersOpen">
                    <x-filament::icon icon="heroicon-o-funnel" /> Filter tanggal
                </button>
            </div>

            <div class="fm-history-filters" x-show="historyFiltersOpen" x-transition.opacity.duration.150ms x-cloak>
                <button type="button" wire:click="showTodaySessions" @class(['is-active' => $historyDate === now('Asia/Jakarta')->toDateString()])>
                    Hari ini
                </button>
                <button type="button" wire:click="showAllSessionDates" @class(['is-active' => $historyDate === ''])>
                    Semua tanggal
                </button>
                <label>
                    <span>Pilih tanggal khusus</span>
                    <input type="date" wire:model.live="historyDate" max="{{ now('Asia/Jakarta')->toDateString() }}">
                </label>
            </div>

            @if ($sessions->isEmpty())
                <div class="fm-empty fm-empty--small">Belum ada folder sesi foto.</div>
            @else
                <div class="fm-session-list">
                    @foreach ($sessions as $session)
                        <div class="fm-session-row-wrap">
                            <a
                                href="{{ \App\Filament\Pages\FotoBarangFolder::getUrl(['session' => $session->uuid]) }}"
                                wire:navigate
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
                            </a>
                            @if (! $session->isActive())
                                <button
                                    type="button"
                                    class="fm-session-delete"
                                    x-on:click="requestDeleteFolder(@js($session->id), @js($session->uuid), @js($session->judul))"
                                    aria-label="Hapus folder {{ $session->judul }}"
                                >
                                    <x-filament::icon icon="heroicon-m-trash" />
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($sessions->hasPages())
                    <div class="fm-pagination">
                        {{ $sessions->onEachSide(1)->links() }}
                    </div>
                @endif
            @endif
        </section>

        <dialog
            x-ref="confirmDialog"
            class="fm-confirm"
            wire:ignore
            x-on:cancel.prevent="closeConfirm()"
            aria-label="Konfirmasi penghapusan"
        >
                <section class="fm-confirm__card" x-on:click.stop>
                    <div class="fm-confirm__icon"><x-filament::icon icon="heroicon-o-trash" /></div>
                    <h2 x-text="confirmTitle"></h2>
                    <p x-text="confirmMessage"></p>
                    <label x-show="confirmRequiresText" x-cloak>
                        <span>Ketik <b>hapus</b> untuk mengonfirmasi</span>
                        <input
                            type="text"
                            x-ref="confirmTextInput"
                            x-model="confirmInput"
                            x-on:keydown.enter.prevent="executeConfirmedDelete()"
                            autocomplete="off"
                            placeholder="Ketik hapus"
                        >
                    </label>
                    <div class="fm-confirm__actions">
                        <button type="button" class="is-cancel" x-on:click="closeConfirm()" x-bind:disabled="confirmBusy">Batal</button>
                        <button
                            type="button"
                            class="is-delete"
                            x-on:click="executeConfirmedDelete()"
                            x-bind:disabled="confirmBusy"
                        >
                            <span x-show="! confirmBusy">Hapus Permanen</span>
                            <span x-show="confirmBusy">Menghapus...</span>
                        </button>
                    </div>
                </section>
        </dialog>
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
        .fm-start-card,.fm-capture-card,.fm-gallery,.fm-history,.fm-session-header { border:1px solid var(--fm-line); border-radius:1.15rem; background:var(--fi-body-bg,#fff); box-shadow:0 9px 25px rgba(15,23,42,.05); }
        .dark .fm-start-card,.dark .fm-capture-card,.dark .fm-gallery,.dark .fm-history,.dark .fm-session-header { background:#111c2b; }
        .fm-start-card,.fm-capture-card,.fm-gallery,.fm-history { padding:1.25rem; }
        .fm-section-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; }
        .fm-section-heading--compact { align-items:center; }
        .fm-section-heading h2 { margin:.25rem 0 0; font-size:1.08rem; letter-spacing:-.015em; }
        .fm-section-heading p { margin:.3rem 0 0; color:var(--fm-muted); font-size:.76rem; line-height:1.5; }
        .fm-section-heading>svg { width:2.2rem; height:2.2rem; padding:.5rem; border-radius:.7rem; color:#b77905; background:#fff5d8; }
        .dark .fm-section-heading>svg { color:#fbbf24; background:#2d281c; }
        .fm-server-heading-actions { display:flex; align-items:center; gap:.5rem; }
        .fm-server-heading-actions>svg { width:2.2rem; height:2.2rem; padding:.5rem; border-radius:.7rem; color:#b77905; background:#fff5d8; }
        .dark .fm-server-heading-actions>svg { color:#fbbf24; background:#2d281c; }
        .fm-server-heading-actions>button { display:flex; align-items:center; gap:.3rem; min-height:2.2rem; padding:.45rem .65rem; border:1px solid var(--fm-line); border-radius:.65rem; color:var(--fm-ink); background:var(--fm-soft); font-size:.63rem; font-weight:800; }
        .fm-server-heading-actions>button svg { width:.9rem; color:#b77905; }
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
        .fm-share-all-button { display:inline-flex; align-items:center; justify-content:center; gap:.4rem; min-height:2.25rem; padding:.48rem .75rem; border:0; border-radius:.55rem; color:#fff; background:#d97706; font-size:.72rem; font-weight:700; box-shadow:0 1px 2px rgba(0,0,0,.08); cursor:pointer; }
        .fm-share-all-button:hover { background:#b45309; }
        .fm-share-all-button:disabled { opacity:.55; cursor:wait; }
        .fm-share-all-button svg { width:1rem; }
        .fm-recovery { display:grid; grid-template-columns:auto minmax(0,1fr) auto auto; gap:.7rem; align-items:center; padding:.78rem .85rem; border:1px solid #a7d8bc; border-radius:.85rem; color:#14532d; background:linear-gradient(135deg,#f0fdf4,#ecfdf5); box-shadow:0 8px 20px rgba(21,128,61,.07); }
        .dark .fm-recovery { border-color:#28563b; color:#bbf7d0; background:linear-gradient(135deg,#142a20,#13261f); }
        .fm-recovery>span { display:grid; place-items:center; width:2.25rem; height:2.25rem; border-radius:.65rem; color:#15803d; background:#dcfce7; }
        .dark .fm-recovery>span { color:#86efac; background:#1c3d2a; }
        .fm-recovery svg { width:1.1rem; }
        .fm-recovery>div { display:grid; gap:.1rem; }
        .fm-recovery strong { font-size:.7rem; }
        .fm-recovery small { color:#3f6f50; font-size:.61rem; }
        .dark .fm-recovery small { color:#86b89a; }
        .fm-recovery button { min-height:2.15rem; padding:.4rem .65rem; border:0; border-radius:.6rem; color:#fff; background:#15803d; font-size:.62rem; font-weight:800; }
        .fm-recovery button.is-muted { color:#3f6f50; background:transparent; }
        .dark .fm-recovery button.is-muted { color:#9ccbad; }
        .fm-share-status { display:flex; align-items:center; gap:.45rem; padding:.62rem .75rem; border:1px solid #fde68a; border-radius:.72rem; color:#854d0e; background:#fffbeb; font-size:.64rem; font-weight:700; }
        .dark .fm-share-status { border-color:#58441b; color:#fde68a; background:#2c2517; }
        .fm-share-status svg { flex:0 0 auto; width:.95rem; }
        .fm-background-queue { display:flex; align-items:center; gap:.5rem; padding:.68rem .8rem; border:1px solid #bfdbfe; border-radius:.75rem; color:#1e3a8a; background:#eff6ff; font-size:.68rem; font-weight:700; }
        .dark .fm-background-queue { border-color:#28496d; color:#bfdbfe; background:#142439; }
        .fm-background-queue svg { flex:0 0 auto; width:1rem; }
        .fm-background-queue b { margin-left:auto; }
        .fm-background-queue button { padding:.27rem .5rem; border:1px solid currentColor; border-radius:999px; color:inherit; background:transparent; font-size:.6rem; font-weight:800; }
        .fm-workspace { display:block; }
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
        .fm-gps small { display:-webkit-box; overflow:hidden; margin-top:.12rem; color:var(--fm-muted); font-size:.58rem; line-height:1.35; -webkit-box-orient:vertical; -webkit-line-clamp:2; }
        .fm-gps__actions { display:flex !important; flex-wrap:wrap; justify-content:flex-end; gap:.25rem !important; }
        .fm-gps button { padding:.34rem .48rem; border:0; border-radius:.55rem; color:#9a6700; background:transparent; font-size:.67rem; font-weight:800; cursor:pointer; }
        .fm-gps button:disabled { opacity:.55; cursor:wait; }
        .fm-gps .fm-gps__template { color:#9f1239; background:rgba(244,63,94,.08); }
        .fm-gps .fm-gps__template.is-active { color:#fff; background:#be123c; }
        .dark .fm-gps .fm-gps__template { color:#fda4af; background:rgba(244,63,94,.15); }
        .dark .fm-gps .fm-gps__template.is-active { color:#fff; background:#be123c; }
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
        .fm-open-camera:disabled { opacity:.55; cursor:wait; }
        .fm-open-camera>span:first-child { display:grid; place-items:center; width:2.7rem; height:2.7rem; border-radius:.75rem; background:rgba(255,255,255,.18); }
        .fm-open-camera>span:nth-child(2) { display:grid; gap:.1rem; }
        .fm-open-camera svg { width:1.3rem; }
        .fm-open-camera>svg { width:1rem; }
        .fm-open-camera strong { font-size:.82rem; }
        .fm-open-camera small { color:#fff7d6; font-size:.65rem; }
        .fm-camera-error { margin:.65rem 0 0; padding:.65rem .75rem; border-radius:.65rem; color:#991b1b; background:#fee2e2; font-size:.68rem; }
        .fm-spinner { width:2.2rem; height:2.2rem; border:3px solid rgba(255,255,255,.25); border-top-color:#fbbf24; border-radius:50%; animation:fm-spin .75s linear infinite; }
        @keyframes fm-spin { to { transform:rotate(360deg); } }
        .fm-capture-note { display:flex; gap:.55rem; margin-top:.9rem; color:var(--fm-muted); }
        .fm-capture-note svg { flex:0 0 auto; width:1rem; color:#d68d05; }
        .fm-capture-note p { margin:0; font-size:.67rem; line-height:1.5; }
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
        .fm-local-preview__panel>div { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.6rem; }
        .fm-local-preview__panel>div button { display:flex; align-items:center; justify-content:center; gap:.35rem; min-height:2.5rem; border:1px solid #334155; border-radius:.65rem; color:#fff; background:#172033; font-size:.7rem; font-weight:800; }
        .fm-local-preview__panel>div svg { width:1rem; }
        .fm-empty { display:grid; justify-items:center; gap:.35rem; padding:2.4rem 1rem; border:1px dashed var(--fm-line); border-radius:.9rem; color:var(--fm-muted); text-align:center; }
        .fm-empty svg { width:2.5rem; opacity:.55; }
        .fm-empty strong { color:var(--fm-ink); font-size:.8rem; }
        .fm-empty span,.fm-empty--small { font-size:.68rem; }
        .fm-empty--small { padding:1.2rem; }
        .fm-photo-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.9rem; }
        .fm-server-selection { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.7rem .8rem; border:1px solid #bfdbfe; border-radius:.8rem; background:#eff6ff; }
        .dark .fm-server-selection { border-color:#244d78; background:#102a44; }
        .fm-server-selection>div { display:flex; align-items:center; flex-wrap:wrap; gap:.4rem; }
        .fm-server-selection>div:first-child { color:#1d4ed8; font-size:.72rem; }
        .dark .fm-server-selection>div:first-child { color:#bfdbfe; }
        .fm-server-selection>div:first-child svg { width:1.05rem; }
        .fm-server-selection button { display:flex; align-items:center; justify-content:center; gap:.28rem; min-height:2.15rem; padding:.4rem .55rem; border:1px solid #bfdbfe; border-radius:.55rem; color:#1e40af; background:#fff; font-size:.62rem; font-weight:800; }
        .dark .fm-server-selection button { border-color:#31587e; color:#dbeafe; background:#122238; }
        .fm-server-selection button svg { width:.85rem; }
        .fm-server-selection button.is-download { border-color:#2563eb; color:#fff; background:#2563eb; }
        .fm-server-selection button.is-delete { border-color:#dc2626; color:#fff; background:#dc2626; }
        .fm-server-selection button:disabled { opacity:.45; cursor:not-allowed; }
        .fm-photo-card { overflow:hidden; border:1px solid var(--fm-line); border-radius:.9rem; background:var(--fm-soft); transition:border-color .15s,box-shadow .15s,transform .15s; }
        .fm-photo-card.is-selected { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.17); transform:translateY(-1px); }
        .fm-photo-card__image { position:relative; display:block; width:100%; aspect-ratio:4/5; overflow:hidden; padding:0; border:0; background:#0f172a; cursor:pointer; touch-action:pan-y; user-select:none; -webkit-touch-callout:none; }
        .fm-photo-card__image img { width:100%; height:100%; object-fit:contain; opacity:0; transform:scale(1.015); transition:opacity .24s ease,transform .32s ease; }
        .fm-photo-card__image img.is-ready { opacity:1; transform:scale(1); }
        .fm-photo-card__image>.fm-photo-sequence { position:absolute; z-index:3; top:.55rem; left:.55rem; padding:.3rem .45rem; border-radius:.5rem; color:#1f1708; background:#fbbf24; font-size:.62rem; font-weight:850; }
        .fm-photo-selection { position:absolute; z-index:4; top:.5rem; right:.5rem; display:grid; place-items:center; width:1.75rem; height:1.75rem; border-radius:50%; background:rgba(15,23,42,.72); }
        .fm-photo-selection i { display:grid; place-items:center; width:1.2rem; height:1.2rem; border:2px solid #fff; border-radius:50%; color:#fff; background:transparent; }
        .fm-photo-selection i.is-checked { border-color:#2563eb; background:#2563eb; }
        .fm-photo-selection svg { width:.75rem; }
        .fm-image-skeleton { position:absolute; z-index:1; inset:0; display:grid; place-items:center; background:linear-gradient(110deg,#172235 8%,#26354a 22%,#172235 36%); background-size:220% 100%; animation:fm-skeleton 1.25s ease-in-out infinite; opacity:1; transition:opacity .18s ease; pointer-events:none; }
        .fm-image-skeleton::after { content:''; position:absolute; right:16%; bottom:18%; left:16%; height:30%; border-radius:.7rem; background:rgba(255,255,255,.055); }
        .fm-image-skeleton small { display:none; position:relative; z-index:1; padding:1rem; color:#94a3b8; font-size:.61rem; font-weight:700; }
        .fm-photo-card__image.is-image-ready .fm-image-skeleton { visibility:hidden; opacity:0; animation:none; }
        .fm-photo-card__image.is-image-failed .fm-image-skeleton { background:#111c2d; animation:none; }
        .fm-photo-card__image.is-image-failed .fm-image-skeleton::after { display:none; }
        .fm-photo-card__image.is-image-failed .fm-image-skeleton small { display:block; }
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
        .fm-server-viewer { position:fixed; inset:0; grid-template-rows:auto minmax(0,1fr) auto; width:100%; max-width:none; height:100dvh; max-height:none; margin:0; padding:0; border:0; color:#fff; background:#03060a; overflow:hidden; }
        .fm-server-viewer[open] { display:grid; }
        .fm-server-viewer::backdrop { background:#03060a; }
        .fm-server-viewer__header { display:grid; grid-template-columns:minmax(5rem,1fr) auto minmax(5rem,1fr); gap:.7rem; align-items:center; padding:calc(.65rem + env(safe-area-inset-top)) .8rem .65rem; background:#0b111a; }
        .fm-server-viewer__header>button { display:flex; align-items:center; gap:.35rem; width:max-content; min-height:2.4rem; padding:.45rem .65rem; border:1px solid #334155; border-radius:999px; color:#fff; background:#172131; font-size:.68rem; font-weight:800; }
        .fm-server-viewer__header>button.is-danger { justify-self:end; width:2.4rem; padding:.45rem; color:#fecaca; border-color:#71343c; background:#361820; }
        .fm-server-viewer__header svg { width:1rem; }
        .fm-server-viewer__header>div { display:grid; justify-items:center; }
        .fm-server-viewer__header strong { font-size:.78rem; }
        .fm-server-viewer__header span { color:#aab7c7; font-size:.61rem; }
        .fm-server-viewer__stage { position:relative; display:grid; place-items:center; min-height:0; overflow:hidden; padding:.4rem; touch-action:pan-y; }
        .fm-server-viewer__stage>img { max-width:100%; max-height:100%; width:auto; height:auto; object-fit:contain; opacity:0; transition:opacity .28s ease; user-select:none; -webkit-user-drag:none; }
        .fm-server-viewer__stage>img.is-ready { opacity:1; }
        .fm-server-viewer__loading { position:absolute; z-index:3; inset:0; display:grid; place-content:center; justify-items:center; gap:.55rem; color:#dbeafe; background:rgba(3,6,10,.72); font-size:.68rem; pointer-events:none; }
        .fm-server-viewer__loading.is-skeleton { align-content:center; background:#03060a; }
        .fm-server-viewer__loading.is-skeleton>span { width:min(78vw,28rem); aspect-ratio:4/5; border-radius:1rem; background:linear-gradient(110deg,#0e1724 8%,#1c2a3d 22%,#0e1724 36%); background-size:220% 100%; animation:fm-skeleton 1.25s ease-in-out infinite; }
        .fm-server-viewer__loading.is-skeleton>small { margin-top:.2rem; color:#8291a5; font-size:.62rem; letter-spacing:.02em; }
        .fm-server-viewer__loading .fm-spinner { width:1.8rem; height:1.8rem; }
        .fm-server-viewer__loading.is-error { color:#fecaca; background:rgba(3,6,10,.9); }
        .fm-server-viewer__loading.is-error span { color:#aab7c7; font-size:.61rem; }
        .fm-server-viewer__nav { position:absolute; z-index:2; top:50%; display:grid; place-items:center; width:2.7rem; height:2.7rem; border:1px solid rgba(255,255,255,.2); border-radius:50%; color:#fff; background:rgba(8,15,24,.72); transform:translateY(-50%); }
        .fm-server-viewer__nav.is-prev { left:.7rem; }
        .fm-server-viewer__nav.is-next { right:.7rem; }
        .fm-server-viewer__nav svg { width:1.2rem; }
        .fm-server-viewer__footer { display:flex; align-items:center; justify-content:space-between; gap:.8rem; padding:.65rem .8rem calc(.7rem + env(safe-area-inset-bottom)); background:#0b111a; }
        .fm-server-viewer__footer>span { color:#aab7c7; font-size:.64rem; }
        .fm-server-viewer__footer>div { display:flex; gap:.5rem; }
        .fm-server-viewer__footer button,.fm-server-viewer__footer a { display:flex; align-items:center; justify-content:center; gap:.3rem; min-height:2.35rem; padding:.45rem .7rem; border:1px solid #334155; border-radius:.6rem; color:#fff; background:#172131; font-size:.65rem; font-weight:800; text-decoration:none; }
        .fm-server-viewer__footer svg { width:.95rem; }
        .fm-confirm { position:fixed; inset:0; place-items:center; width:100%; max-width:none; height:100dvh; max-height:none; margin:0; padding:1rem; border:0; color:inherit; background:transparent; overflow:hidden; }
        .fm-confirm[open] { display:grid; }
        .fm-confirm::backdrop { background:rgba(3,7,13,.64); backdrop-filter:blur(9px); -webkit-backdrop-filter:blur(9px); }
        .fm-confirm__card { position:relative; z-index:1; display:grid; justify-items:center; max-width:25rem; width:100%; padding:1.35rem; border:1px solid rgba(255,255,255,.14); border-radius:1.25rem; color:#eef4fb; background:linear-gradient(155deg,rgba(28,39,54,.98),rgba(11,18,28,.98)); box-shadow:0 25px 70px rgba(0,0,0,.45); text-align:center; }
        .fm-confirm__icon { display:grid; place-items:center; width:3.5rem; height:3.5rem; border-radius:1rem; color:#fecaca; background:linear-gradient(145deg,#7f1d2d,#42151e); box-shadow:0 10px 25px rgba(127,29,45,.28); }
        .fm-confirm__icon svg { width:1.65rem; }
        .fm-confirm h2 { margin:.8rem 0 0; font-size:1.08rem; }
        .fm-confirm p { margin:.4rem 0 0; color:#aebaca; font-size:.72rem; line-height:1.55; }
        .fm-confirm label { display:grid; gap:.4rem; width:100%; margin-top:1rem; text-align:left; }
        .fm-confirm label span { color:#cbd5e1; font-size:.67rem; }
        .fm-confirm label b { color:#fca5a5; }
        .fm-confirm input { width:100%; padding:.72rem .8rem; border:1px solid #45566b; border-radius:.7rem; color:#fff; background:#0c1420; font-size:.78rem; outline:none; }
        .fm-confirm input:focus { border-color:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.15); }
        .fm-confirm__actions { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.65rem; width:100%; margin-top:1.1rem; }
        .fm-confirm__actions button { min-height:2.6rem; border-radius:.7rem; font-size:.7rem; font-weight:850; }
        .fm-confirm__actions .is-cancel { border:1px solid #45566b; color:#e2e8f0; background:#172131; }
        .fm-confirm__actions .is-delete { border:1px solid #ef4444; color:#fff; background:linear-gradient(135deg,#dc2626,#991b1b); }
        .fm-confirm__actions button:disabled { opacity:.45; cursor:not-allowed; }
        .fm-history-filter-button { display:flex; align-items:center; gap:.35rem; padding:.55rem .75rem; border:1px solid var(--fm-line); border-radius:.65rem; color:var(--fm-ink); background:var(--fm-soft); font-size:.65rem; font-weight:800; cursor:pointer; }
        .fm-history-filter-button svg { width:1rem; }
        .fm-history-filters { display:flex; flex-wrap:wrap; gap:.55rem; align-items:end; padding:.75rem; border:1px solid var(--fm-line); border-radius:.8rem; background:var(--fm-soft); }
        .fm-history-filters>button { min-height:2.35rem; padding:.5rem .7rem; border:1px solid var(--fm-line); border-radius:.6rem; color:var(--fm-ink); background:var(--fi-body-bg,#fff); font-size:.64rem; font-weight:800; }
        .fm-history-filters>button.is-active { color:#92400e; border-color:#f59e0b; background:#fef3c7; }
        .fm-history-filters label { display:grid; gap:.25rem; margin-left:auto; }
        .fm-history-filters label span { color:var(--fm-muted); font-size:.58rem; font-weight:750; }
        .fm-history-filters input { min-height:2.35rem; padding:.4rem .6rem; border:1px solid var(--fm-line); border-radius:.6rem; color:var(--fm-ink); background:var(--fi-body-bg,#fff); font-size:.68rem; }
        .fm-session-list { display:grid; overflow:hidden; border:1px solid var(--fm-line); border-radius:.9rem; }
        .fm-session-row-wrap { display:flex; align-items:stretch; border-bottom:1px solid var(--fm-line); }
        .fm-session-row-wrap:last-child { border-bottom:0; }
        .fm-session-row { display:grid; flex:1; grid-template-columns:auto minmax(0,1fr) auto auto auto; gap:.75rem; align-items:center; min-width:0; padding:.8rem; border:0; color:var(--fm-ink); background:transparent; text-align:left; text-decoration:none; cursor:pointer; }
        .fm-session-row:hover,.fm-session-row.is-current { background:var(--fm-soft); }
        .fm-session-row__icon { display:grid; place-items:center; width:2.2rem; height:2.2rem; border-radius:.65rem; color:#b77905; background:#fff3cf; }
        .dark .fm-session-row__icon { color:#fbbf24; background:#30291b; }
        .fm-session-row__icon svg,.fm-session-row>svg { width:1.05rem; }
        .fm-session-row__main { display:grid; gap:.15rem; min-width:0; }
        .fm-session-row__main strong { overflow:hidden; font-size:.72rem; text-overflow:ellipsis; white-space:nowrap; }
        .fm-session-row__main small,.fm-session-row__count { color:var(--fm-muted); font-size:.61rem; }
        .fm-session-delete { display:grid; place-items:center; flex:0 0 3rem; border:0; border-left:1px solid var(--fm-line); color:#dc2626; background:transparent; cursor:pointer; }
        .fm-session-delete:hover { background:#fef2f2; }
        .dark .fm-session-delete:hover { background:#351820; }
        .fm-session-delete svg { width:1.05rem; }
        .fm-pagination { overflow-x:auto; padding-top:.15rem; }
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
        .fm-live-camera__stage { --fm-vertical-crop:{{ ((float) config('foto_barang.vertical_crop_ratio', 0.045)) * 100 }}%; position:relative; min-height:0; overflow:hidden; background:#000; }
        .fm-live-camera__stage>video { width:100%; height:100%; object-fit:contain; background:#000; }
        .fm-live-camera__shade { position:absolute; inset:0; pointer-events:none; background:linear-gradient(to bottom,rgba(0,0,0,.12),transparent 24%,transparent 58%,rgba(0,0,0,.35)); }
        .fm-live-camera__crop-mask { position:absolute; z-index:1; inset:0; pointer-events:none; border-top:var(--fm-vertical-crop) solid rgba(0,0,0,.72); border-bottom:var(--fm-vertical-crop) solid rgba(0,0,0,.72); }
        .fm-live-camera__badge { position:absolute; z-index:2; right:0; bottom:calc(100% + .4rem); display:flex; align-items:center; gap:.38rem; padding:.42rem .58rem; border-radius:.15rem; color:#fff; background:rgba(2,7,13,.72); font-size:clamp(.63rem,2.5vw,.9rem); font-weight:800; letter-spacing:.015em; text-shadow:0 1px 2px #000; }
        .fm-live-camera__badge i { width:.52rem; height:.52rem; border-radius:50%; background:#fbbf24; box-shadow:0 0 0 2px rgba(255,255,255,.35); }
        .fm-live-camera__watermark { position:absolute; z-index:2; right:.55rem; bottom:calc(var(--fm-vertical-crop) + .35rem); left:.55rem; padding:clamp(.72rem,2.4vw,1.25rem); color:#fff; background:rgba(2,7,13,.76); text-shadow:0 1px 3px rgba(0,0,0,.95); }
        .fm-live-camera__datetime { display:grid; grid-template-columns:auto .24rem minmax(0,1fr); gap:clamp(.65rem,3vw,1.15rem); align-items:center; }
        .fm-live-camera__datetime>strong { font-size:clamp(2.05rem,9.2vw,5.7rem); font-weight:800; line-height:.95; letter-spacing:-.04em; white-space:nowrap; }
        .fm-live-camera__datetime>i { width:.24rem; height:clamp(3.15rem,12vw,6rem); background:#f7b500; }
        .fm-live-camera__datetime>b { font-size:clamp(1.15rem,5vw,3rem); font-weight:800; line-height:1.02; }
        .fm-live-camera__watermark h3 { display:flex; align-items:center; gap:.35rem; overflow:hidden; margin:clamp(.45rem,1.7vw,.8rem) 0 .12rem; font-size:clamp(1rem,4.5vw,2.6rem); font-weight:800; line-height:1.05; text-overflow:ellipsis; white-space:nowrap; }
        .fm-live-camera__watermark h3 span { font-size:.85em; }
        .fm-live-camera__watermark p { display:-webkit-box; overflow:hidden; margin:0; color:#f3f5f8; font-size:clamp(.77rem,3.15vw,1.65rem); font-weight:600; line-height:1.18; -webkit-box-orient:vertical; -webkit-line-clamp:4; }
        .fm-live-camera__watermark>small { display:block; overflow:hidden; margin-top:.22rem; color:#e6ebf1; font-size:clamp(.68rem,2.75vw,1.4rem); font-weight:600; line-height:1.1; text-overflow:ellipsis; white-space:nowrap; }
        .fm-live-camera__flash { position:absolute; z-index:5; inset:0; pointer-events:none; background:#fff; opacity:0; transition:opacity .14s ease-out; }
        .fm-live-camera__flash.is-visible { opacity:.72; transition:none; }
        .fm-live-camera__controls { display:grid; gap:.55rem; padding:.55rem .8rem calc(.65rem + env(safe-area-inset-bottom)); background:#080d14; }
        .fm-live-camera__preferences { display:flex; flex-wrap:wrap; justify-content:center; gap:.4rem; }
        .fm-live-camera__preferences button { display:flex; align-items:center; gap:.3rem; min-height:1.75rem; padding:.28rem .55rem; border:1px solid #475569; border-radius:999px; color:#cbd5e1; background:#111c2a; font-size:.58rem; font-weight:800; }
        .fm-live-camera__preferences button.is-active { border-color:#d69a24; color:#fcd34d; background:#2a2112; }
        .fm-live-camera__preferences button:disabled { opacity:.48; cursor:not-allowed; }
        .fm-live-camera__preferences svg { width:.85rem; }
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
        @media(max-width:900px) { .fm-hero { grid-template-columns:1fr; } }
        @media(max-width:640px) {
            .fm-page { gap:.8rem; }
            .fm-hero { gap:1.15rem; padding:1.15rem; border-radius:1rem; }
            .fm-flow { gap:.3rem; padding:.75rem .5rem; }
            .fm-flow span { font-size:.57rem; }
            .fm-flow b { width:1.7rem; height:1.7rem; }
            .fm-start-card,.fm-capture-card,.fm-gallery,.fm-history { padding:.9rem; border-radius:.9rem; }
            .fm-section-heading { align-items:flex-start; }
            .fm-server-heading-actions>svg { display:none; }
            .fm-server-heading-actions>button { white-space:nowrap; }
            .fm-server-selection { align-items:stretch; flex-direction:column; }
            .fm-server-selection>div:last-child { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); }
            .fm-session-header { align-items:flex-start; flex-direction:column; padding:.9rem; }
            .fm-session-actions { width:100%; justify-content:flex-start; }
            .fm-recovery { grid-template-columns:auto minmax(0,1fr); }
            .fm-recovery button { width:100%; }
            .fm-form { grid-template-columns:1fr; }
            .fm-form__footer { align-items:stretch; flex-direction:column; }
            .fm-gps { grid-template-columns:auto minmax(0,1fr); }
            .fm-gps__actions { grid-column:1/-1; justify-content:flex-start; }
            .fm-mode-picker { grid-template-columns:1fr; }
            .fm-photo-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.6rem; }
            .fm-local-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.6rem; }
            .fm-history-filters { align-items:stretch; flex-direction:column; }
            .fm-history-filters label { width:100%; margin-left:0; }
            .fm-history-filters input { width:100%; }
            .fm-session-row { grid-template-columns:auto minmax(0,1fr) auto; gap:.55rem; }
            .fm-session-row__count { display:none; }
            .fm-session-row .fm-status { grid-column:2; }
            .fm-session-row>svg { grid-column:3; grid-row:1/3; }
            .fm-server-viewer__nav { display:none; }
            .fm-server-viewer__footer { align-items:stretch; flex-direction:column; }
            .fm-server-viewer__footer>div { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); }
            .fm-local-preview__panel>div { grid-template-columns:1fr; }
        }
        @media(max-width:410px) { .fm-photo-grid,.fm-local-grid { grid-template-columns:1fr; } }
        @keyframes fm-skeleton { from { background-position:100% 0; } to { background-position:-100% 0; } }
        @media(prefers-reduced-motion:reduce) { .fm-spinner { animation-duration:1.5s; } .fm-image-skeleton,.fm-server-viewer__loading.is-skeleton>span { animation:none; } }
    </style>
</x-filament-panels::page>
