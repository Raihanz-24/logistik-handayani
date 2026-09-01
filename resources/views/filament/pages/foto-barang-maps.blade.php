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
            async locate() {
                if (! window.isSecureContext && ! ['localhost', '127.0.0.1'].includes(window.location.hostname)) {
                    this.gpsState = 'GPS membutuhkan koneksi HTTPS';
                    return;
                }

                if (! navigator.geolocation) {
                    this.gpsState = 'GPS tidak didukung perangkat ini';
                    return;
                }

                this.locating = true;
                this.gpsState = 'Meminta lokasi perangkat...';

                navigator.geolocation.getCurrentPosition(
                    async (position) => {
                        await $wire.updateCoordinates(
                            position.coords.latitude,
                            position.coords.longitude,
                            position.coords.accuracy,
                        );
                        this.gpsReady = true;
                        this.locating = false;
                        this.gpsState = `GPS aktif · akurasi ±${Math.round(position.coords.accuracy)} meter`;
                    },
                    () => {
                        this.gpsReady = false;
                        this.locating = false;
                        this.gpsState = 'Izin lokasi belum diberikan';
                    },
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
                );
            },
            async sharePhoto(previewUrl, downloadUrl, fileName) {
                try {
                    const response = await fetch(previewUrl, { credentials: 'same-origin' });
                    const blob = await response.blob();
                    const file = new File([blob], fileName, { type: 'image/jpeg' });

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
        x-init="locate()"
        x-on:foto-barang-saved.window="gpsState = gpsReady ? gpsState : 'Periksa kembali lokasi sebelum foto berikutnya'"
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
                    <p>{{ $activeSession->nama_lokasi }} · {{ $activeSession->items_count }} foto</p>
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
                            wire:click="finishSession"
                            wire:confirm="Selesaikan sesi ini? Setelah selesai, foto tidak dapat ditambah atau dihapus."
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

            @if ($activeSession->isActive())
                <div class="fm-workspace">
                    <section class="fm-capture-card">
                        <div class="fm-section-heading fm-section-heading--compact">
                            <div>
                                <span class="fm-section-kicker">Foto berikutnya</span>
                                <h2>Ambil foto ke-{{ $activeSession->items_count + 1 }}</h2>
                            </div>
                            <span class="fm-counter">{{ $activeSession->items_count }}</span>
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
                            <button type="button" x-on:click="locate()" x-bind:disabled="locating">Coba GPS</button>
                        </div>

                        <div class="fm-location-fallback">
                            <p>Jika GPS browser ditolak, gunakan titik Paiton hanya bila foto memang diambil di lokasi ini.</p>
                            <button type="button" wire:click="useDefaultLocation">Gunakan lokasi default Paiton</button>
                        </div>

                        <div class="fm-camera-box" wire:key="camera-input-{{ $uploadKey }}">
                            <input
                                id="foto-barang-camera-{{ $uploadKey }}"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                capture="environment"
                                wire:model="photo"
                            >

                            <label for="foto-barang-camera-{{ $uploadKey }}" class="fm-camera-button">
                                <span><x-filament::icon icon="heroicon-o-camera" /></span>
                                <strong>Ambil Foto Barang</strong>
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
            @endif

            <section class="fm-gallery">
                <div class="fm-section-heading">
                    <div>
                        <span class="fm-section-kicker">Isi folder</span>
                        <h2>{{ $activeSession->items_count }} foto tersimpan</h2>
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
            .fm-photo-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.6rem; }
            .fm-session-row { grid-template-columns:auto minmax(0,1fr) auto; gap:.55rem; }
            .fm-session-row__count { display:none; }
            .fm-session-row .fm-status { grid-column:2; }
            .fm-session-row>svg { grid-column:3; grid-row:1/3; }
        }
        @media(max-width:410px) { .fm-photo-grid { grid-template-columns:1fr; } }
        @media(prefers-reduced-motion:reduce) { .fm-spinner { animation-duration:1.5s; } }
    </style>
</x-filament-panels::page>
