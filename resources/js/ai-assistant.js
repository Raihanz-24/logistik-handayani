/**
 * Asisten AI — perilaku halaman chat penuh (voice, enter-to-send, tinggi, riwayat).
 *
 * Dimuat global lewat render hook (lihat resources/views/filament/ai-assistant.blade.php)
 * supaya tetap hidup saat navigasi SPA Filament (`livewire:navigate`). Semua event
 * dipasang dengan delegasi/sekali-pasang agar tidak menumpuk antar navigasi.
 */

const AI_ROOT = '.ai-page';

const scrollToEnd = () => {
    const box = document.getElementById('ai-chat-scroll');
    if (box) {
        box.scrollTop = box.scrollHeight;
    }
};

const autoGrow = (el) => {
    if (!el) {
        return;
    }
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 128) + 'px';
};

const readConfig = (root) => ({
    lang: root?.dataset.aiLang || 'id-ID',
    rate: parseFloat(root?.dataset.aiRate || '1') || 1,
    autoSpeak: (root?.dataset.aiSpeak || '0') === '1',
});

const voiceSupport = () => {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SR) {
        return { ok: false, reason: 'unsupported' };
    }

    if (!window.isSecureContext) {
        return { ok: false, reason: 'insecure' };
    }

    return { ok: true };
};

const VOICE_MESSAGES = {
    unsupported:
        'Browser ini belum mendukung input suara. Web Speech API tidak tersedia di Opera/Firefox. Silakan gunakan Google Chrome atau Microsoft Edge terbaru.',
    insecure:
        'Input suara butuh koneksi aman (HTTPS) atau localhost. Jika diakses lewat alamat IP LAN, buka lewat http://localhost atau aktifkan HTTPS.',
};

const speak = (text, config) => {
    if (!text || !('speechSynthesis' in window)) {
        return;
    }

    try {
        const utter = new SpeechSynthesisUtterance(text);
        utter.lang = config.lang;
        utter.rate = config.rate;
        window.speechSynthesis.cancel();
        window.speechSynthesis.speak(utter);
    } catch (e) {
        /* diabaikan */
    }
};

const fitHeight = () => {
    const page = document.querySelector('.ai-page-wrap');
    if (!page) {
        return;
    }

    const top = page.getBoundingClientRect().top;
    const main = page.closest('.fi-main') ?? page.closest('main');
    const bottomPad = main ? parseFloat(getComputedStyle(main).paddingBottom) || 0 : 0;

    let available = window.innerHeight - top - bottomPad;

    const bottomNav = document.querySelector('.wm-mobile-nav-wrap');

    if (bottomNav && bottomNav.offsetHeight > 0) {
        available = Math.min(available, bottomNav.getBoundingClientRect().top - top - 10);
    } else {
        const footer = document.querySelector('.wm-global-footer');

        if (footer && footer.offsetHeight > 0) {
            available = Math.min(available, footer.getBoundingClientRect().top - top);
        }
    }

    page.style.setProperty('--ai-page-h', Math.max(320, Math.floor(available)) + 'px');
};

const showVoicePanel = (state, mic) => {
    const panel = document.getElementById('ai-voice-panel');
    const preview = document.getElementById('ai-voice-preview');

    if (panel) {
        panel.hidden = !state;
    }

    if (mic) {
        mic.classList.toggle('is-listening', state);
        mic.setAttribute('aria-pressed', state ? 'true' : 'false');
        mic.setAttribute(
            'title',
            state ? 'Sedang merekam — klik untuk berhenti' : 'Bicara (input suara)',
        );
    }

    if (state && preview) {
        preview.textContent = 'Mulai bicara sekarang';

        // Reset penanda "sudah menangkap suara" setiap sesi baru.
        delete preview.dataset.heard;
    }
};

const notifyVoice = (message, { sticky = false } = {}) => {
    const hint = document.getElementById('ai-voice-hint');

    if (hint) {
        hint.textContent = message;
        hint.hidden = false;
        clearTimeout(window.__aiChat.hintTimer);

        if (! sticky) {
            window.__aiChat.hintTimer = setTimeout(() => {
                hint.hidden = true;
            }, 7000);
        }
    }

    console.warn('[Asisten AI] ' + message);
};

const syncTranscriptToField = (text) => {
    const field = document.getElementById('ai-message-input');

    if (!field || !text) {
        return;
    }

    field.value = text;
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));

    // Cadangan: set langsung ke properti Livewire agar hasil suara pasti tersimpan.
    try {
        const root = field.closest('[wire\\:id]');
        const wireId = root?.getAttribute('wire:id');

        if (wireId && window.Livewire?.find) {
            window.Livewire.find(wireId)?.set('message', text, false);
        }
    } catch (e) {
        /* diabaikan */
    }

    autoGrow(field);
    scrollToEnd();
};

let recognition = null;
let finalTranscript = '';

const isOpera = () => {
    const ua = navigator.userAgent || '';

    // Opera modern berbasis Chromium: UA memuat "OPR/". Opera GX juga.
    return /\bOPR\/|\bOpera\b/i.test(ua) && ! /\bEdg\//.test(ua);
};

/**
 * Beberapa browser mobile (terutama Chrome Android) hanya mengizinkan mikrofon
 * diakses OLEH SATU API pada satu waktu. Bila getUserMedia (pengukur level) dan
 * SpeechRecognition berjalan bersamaan, mikrofon bisa "direbut" sehingga mesin
 * pengenalan hanya menerima audio terputus → gejalanya "suara Anda kurang jelas"
 * atau tidak ada hasil teks sama sekali.
 *
 * Karena itu, pengukur level visual DILEWATI di perangkat sentuh/mobile. Gelombang
 * tetap berjalan (animasi idle) dan tetap memberi respons saat ada suara lewat
 * event speechstart/onsoundstart.
 */
const shouldMeterVoice = () => {
    const ua = navigator.userAgent || '';

    if (/\b(Android|iPhone|iPad|iPod|Mobile)\b/i.test(ua)) {
        return false;
    }

    try {
        if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
            return false;
        }
    } catch (e) {
        /* diabaikan */
    }

    return true;
};

/**
 * Status mikrofon & pengukur level. Dipakai untuk memberi umpan balik nyata:
 * bila panel "Mendengarkan" muncul tetapi tidak ada teks, kita bisa membedakan
 * "tidak ada suara yang masuk" vs "suara masuk tetapi layanan pengenalan diam".
 */
const micCapture = {
    stream: null,
    ctx: null,
    analyser: null,
    raf: null,
    level: 0,
    peak: 0,
    label: '',
    deviceError: '',
};

const MIC_ERROR_MESSAGES = {
    'no-mediadevices': 'Browser ini tidak menyediakan akses mikrofon (getUserMedia). Gunakan Google Chrome atau Microsoft Edge terbaru.',
    denied: 'Akses mikrofon ditolak. Klik ikon kunci/mikrofon di address bar Chrome, izinkan mikrofon untuk situs ini, lalu coba lagi.',
    missing: 'Tidak ada mikrofon yang terdeteksi di perangkat ini. Sambungkan mikrofon, lalu coba lagi.',
    busy: 'Mikrofon sedang dipakai aplikasi lain. Tutup aplikasi tersebut, lalu coba lagi.',
    overconstrained: 'Perangkat mikrofon tidak mendukung setelan yang diminta.',
    unknown: 'Mikrofon tidak dapat diakses. Periksa perangkat dan izin browser.',
};

const releaseStream = (stream) => {
    stream?.getTracks().forEach((track) => {
        try { track.stop(); } catch (e) { /* diabaikan */ }
    });
};

/**
 * Satu AudioContext global yang dibuat & di-resume DI DALAM gesture klik.
 * Ini penting: AudioContext yang dibuat di luar gesture (mis. di dalam onstart)
 * sering berstatus "suspended", sehingga analyser selalu membaca nol dan
 * gelombang tampak diam walau mikrofon bekerja.
 */
let voiceAudioContext = null;

const primeVoiceAudio = () => {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;

    if (! AudioCtx) {
        return;
    }

    try {
        if (! voiceAudioContext) {
            voiceAudioContext = new AudioCtx();
        }

        if (voiceAudioContext.state === 'suspended') {
            voiceAudioContext.resume().catch(() => {});
        }
    } catch (e) {
        /* diabaikan */
    }
};

const setVoiceBars = (level) => {
    const panel = document.getElementById('ai-voice-panel');

    if (! panel) {
        return;
    }

    // Amplitudo animasi membesar mengikuti level suara nyata. Idle tetap
    // bergerak (gain dasar), sehingga terlihat jelas saat mikrofon merekam.
    const gain = Math.min(1.3, 0.7 + level * 9);
    panel.style.setProperty('--ai-mic-gain', gain.toFixed(3));
};

/**
 * Dorongan singkat gelombang sebagai tanda "ada suara" — dipakai di perangkat
 * mobile yang tidak memakai pengukur level (lihat shouldMeterVoice).
 */
let voiceBumpTimer = null;

const bumpVoiceGain = (value = 1.15) => {
    const panel = document.getElementById('ai-voice-panel');

    if (! panel) {
        return;
    }

    panel.style.setProperty('--ai-mic-gain', String(value));
    clearTimeout(voiceBumpTimer);

    voiceBumpTimer = setTimeout(() => {
        // Bila meter asli tidak aktif, balik ke gain default CSS (.7).
        if (! micCapture.analyser) {
            panel.style.removeProperty('--ai-mic-gain');
        }
    }, 700);
};

const updateVoiceLevel = (level) => {
    setVoiceBars(level);
};

const startLevelMeter = (stream) => {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;

    if (! AudioCtx) {
        return;
    }

    try {
        // Pakai context yang sudah di-prime dalam gesture klik bila ada.
        const ctx = voiceAudioContext || (voiceAudioContext = new AudioCtx());

        if (ctx.state === 'suspended') {
            ctx.resume().catch(() => {});
        }

        const source = ctx.createMediaStreamSource(stream);
        const analyser = ctx.createAnalyser();
        analyser.fftSize = 512;
        analyser.smoothingTimeConstant = 0.6;
        source.connect(analyser);

        micCapture.ctx = ctx;
        micCapture.analyser = analyser;

        const data = new Uint8Array(analyser.fftSize);

        const tick = () => {
            analyser.getByteTimeDomainData(data);

            let sum = 0;
            for (let i = 0; i < data.length; i++) {
                const v = (data[i] - 128) / 128;
                sum += v * v;
            }

            const rms = Math.sqrt(sum / data.length);
            micCapture.level = rms;
            micCapture.peak = Math.max(micCapture.peak, rms);
            updateVoiceLevel(rms);

            micCapture.raf = requestAnimationFrame(tick);
        };

        tick();
    } catch (e) {
        /* Pengukur level bersifat opsional; lewati bila gagal. */
    }
};

const stopLevelMeter = () => {
    if (micCapture.raf) {
        cancelAnimationFrame(micCapture.raf);
        micCapture.raf = null;
    }

    // Jangan tutup voiceAudioContext (global, dipakai ulang); cukup lepas sumber.
    micCapture.analyser = null;
    micCapture.level = 0;

    releaseStream(micCapture.stream);
    micCapture.stream = null;

    clearTimeout(voiceBumpTimer);

    const panel = document.getElementById('ai-voice-panel');

    if (panel) {
        // Kembali ke gain default CSS (idle) — tanpa sisa dorongan suara terakhir.
        panel.style.removeProperty('--ai-mic-gain');
    }
};

/** Mulai mengukur level SETELAH recognition memulai, agar tidak berebut perangkat. */
const beginMetering = async () => {
    micCapture.peak = 0;
    micCapture.level = 0;
    micCapture.deviceError = '';

    // Di perangkat mobile: JANGAN pakai getUserMedia (akan merebut mic dari
    // SpeechRecognition). Cukup andalkan event suara recognition.
    if (! shouldMeterVoice()) {
        micCapture.deviceError = '';
        return;
    }

    if (! navigator.mediaDevices?.getUserMedia) {
        micCapture.deviceError = 'no-mediadevices';
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const track = stream.getAudioTracks()[0] || null;
        micCapture.label = (track?.label || '').trim();
        micCapture.stream = stream;
        startLevelMeter(stream);
    } catch (error) {
        const name = error?.name || '';

        if (name === 'NotAllowedError' || name === 'SecurityError') {
            micCapture.deviceError = 'denied';
        } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
            micCapture.deviceError = 'missing';
        } else if (name === 'NotReadableError' || name === 'TrackStartError') {
            micCapture.deviceError = 'busy';
        } else {
            micCapture.deviceError = 'unknown';
        }
    }
};

const noteVoiceDiagnostic = (stage, detail = {}) => {
    // Hanya untuk penelusuran: tidak menampilkan data sensitif, sekadar status mic.
    console.info('[Asisten AI][voice]', stage, {
        lang: detail.lang,
        device: micCapture.label || undefined,
        deviceError: micCapture.deviceError || undefined,
        peak: Number.isFinite(micCapture.peak) ? micCapture.peak.toFixed(4) : undefined,
        secure: window.isSecureContext,
        protocol: window.location?.protocol,
        codes: detail.codes,
    });
};

const diagnoseEmptyResult = () => {
    // Tidak ada teks sama sekali. Bedakan beberapa sebab berdasar pengukuran nyata,
    // supaya pengguna tahu langkah perbaikannya (bukan panel yang diam saja).
    if (micCapture.deviceError && MIC_ERROR_MESSAGES[micCapture.deviceError]) {
        noteVoiceDiagnostic('empty:device-error', { codes: micCapture.deviceError });
        notifyVoice(MIC_ERROR_MESSAGES[micCapture.deviceError], { sticky: true });

        return;
    }

    if (! micCapture.analyser) {
        // Pengukur level tidak tersedia / sengaja dilewati (mobile).
        noteVoiceDiagnostic('empty:no-meter');

        if (! shouldMeterVoice()) {
            notifyVoice(
                'Belum ada teks yang dikenali. Pastikan bicara setelah gelombang aktif, pakai Chrome, dan koneksi internet stabil.',
                { sticky: true },
            );
        } else {
            notifyVoice(
                'Belum ada teks yang dikenali. Bicara setelah indikator aktif, periksa mikrofon default di Chrome, dan pastikan koneksi internet stabil.',
                { sticky: true },
            );
        }

        return;
    }

    if (micCapture.peak < 0.015) {
        noteVoiceDiagnostic('empty:no-sound');
        notifyVoice(
            'Tidak ada suara yang terdeteksi. Cek mikrofon default di Chrome (ikon mikrofon di address bar → pilih mikrofon yang benar) dan pastikan tidak dalam kondisi bisu.',
            { sticky: true },
        );

        return;
    }

    noteVoiceDiagnostic('empty:unrecognized');
    notifyVoice(
        'Suara Anda terekam tetapi Chrome tidak mengenalinya. Coba bicara lebih dekat dan jelas, atau periksa koneksi internet (pengenalan suara Chrome memakai layanan online).',
        { sticky: true },
    );
};

const startRecognition = (config) => {
    const micButton = document.getElementById('ai-mic-button');

    if (window.__aiChat.starting) {
        return;
    }

    const support = voiceSupport();

    if (! support.ok) {
        notifyVoice(VOICE_MESSAGES[support.reason], { sticky: true });
        return;
    }

    if (isOpera()) {
        notifyVoice(
            'Opera tidak mendukung input suara (Web Speech API). Silakan buka halaman ini di Google Chrome atau Microsoft Edge.',
            { sticky: true },
        );
        return;
    }

    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;

    // PENTING: start() dipanggil sinkron di dalam gesture klik (tanpa await
    // sebelumnya) supaya Chrome tidak menolak karena kehilangan user activation.
    // AudioContext (untuk meter) hanya disiapkan bila memang dipakai.
    if (shouldMeterVoice()) {
        primeVoiceAudio();
    }

    recognition = new SR();
    recognition.lang = micButton?.dataset.lang || config.lang;
    recognition.interimResults = true;
    recognition.continuous = false;
    recognition.maxAlternatives = 3;

    finalTranscript = '';
    window.__aiChat.starting = true;

    let started = false;
    let gotResult = false;
    let hadError = false;

    const clearWatchdog = () => {
        clearTimeout(window.__aiChat.voiceWatchdog);
        clearTimeout(window.__aiChat.resultWatchdog);
    };

    recognition.onstart = () => {
        started = true;
        clearWatchdog();
        showVoicePanel(true, micButton);
        noteVoiceDiagnostic('start', { lang: recognition.lang });

        // Ukur level mikrofon secara paralel (opsional & non-blocking) untuk
        // memberi diagnosa tepat bila tidak ada teks sama sekali.
        beginMetering();

        // Watchdog "ada audio tapi tak ada teks": umum terjadi bila layanan
        // pengenalan Chrome tidak mengembalikan hasil (mis. jaringan terblokir).
        window.__aiChat.resultWatchdog = setTimeout(() => {
            if (! gotResult) {
                notifyVoice(
                    'Belum ada teks yang dikenali. Bicara setelah indikator aktif, periksa koneksi internet, atau ganti mikrofon default di pengaturan Chrome.',
                    { sticky: true },
                );
            }
        }, 7000);
    };

    recognition.onaudiostart = () => {
        const preview = document.getElementById('ai-voice-preview');

        if (preview) {
            preview.textContent = 'Mendengarkan suara…';
        }
    };

    // Respons visual saat ada suara. Juga berlaku di mobile (tanpa meter) dan
    // membantu memastikan audio benar-benar diterima mesin pengenalan.
    const onHeardSound = () => {
        if (! micCapture.analyser) {
            bumpVoiceGain();
        }
    };

    recognition.onsoundstart = onHeardSound;
    recognition.onspeechstart = onHeardSound;

    recognition.onend = () => {
        clearWatchdog();
        showVoicePanel(false, micButton);

        if (! gotResult && ! hadError) {
            diagnoseEmptyResult();
        }

        stopLevelMeter();
        window.__aiChat.starting = false;
    };

    recognition.onerror = (event) => {
        hadError = true;
        clearWatchdog();
        showVoicePanel(false, micButton);
        stopLevelMeter();
        window.__aiChat.starting = false;

        const code = event?.error || '';

        if (code !== 'aborted') {
            noteVoiceDiagnostic('error', { codes: code });
        }

        if (code === 'not-allowed' || code === 'service-not-allowed') {
            notifyVoice('Akses mikrofon ditolak. Izinkan mikrofon untuk situs ini di pengaturan browser.');
        } else if (code === 'audio-capture') {
            notifyVoice('Mikrofon tidak terdeteksi pada perangkat ini.');
        } else if (code === 'network') {
            notifyVoice('Pengenalan suara butuh koneksi internet yang stabil.');
        } else if (code === 'no-speech') {
            notifyVoice('Belum ada suara yang terdeteksi. Coba bicara lebih dekat ke mikrofon.');
        } else if (code && code !== 'aborted') {
            notifyVoice('Gagal mendengarkan suara (' + code + ').');
        }
    };

    recognition.onresult = (event) => {
        let interim = '';
        let final = '';

        for (let i = event.resultIndex; i < event.results.length; i++) {
            const result = event.results[i];
            const text = result[0]?.transcript || '';

            if (result.isFinal) {
                final += text;
            } else {
                interim += text;
            }
        }

        if (final) {
            finalTranscript += final;
        }

        const combined = (finalTranscript || interim).trim();

        if (combined) {
            gotResult = true;
        }

        const preview = document.getElementById('ai-voice-preview');

        if (combined && preview) {
            preview.textContent = combined;
        }

        if (final) {
            // Hanya sinkronkan ke Livewire saat hasil final agar tidak spam request.
            syncTranscriptToField(combined);
        } else if (combined) {
            const field = document.getElementById('ai-message-input');
            if (field) {
                field.value = combined;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                autoGrow(field);
            }
        }
    };

    try {
        recognition.start();

        // Watchdog: sebagian browser (mis. Edge) membuat objeknya tetapi tidak
        // pernah memulai. Jika tak ada sinyal apa pun, beri tahu pengguna.
        window.__aiChat.voiceWatchdog = setTimeout(() => {
            if (! started) {
                showVoicePanel(false, micButton);
                notifyVoice(
                    'Mikrofon tidak merespons. Pastikan Anda mengizinkan akses mikrofon, lalu coba lagi dengan Chrome atau Edge terbaru.',
                    { sticky: true },
                );
                stopRecognition();
                window.__aiChat.starting = false;
            }
        }, 3000);
    } catch (e) {
        clearWatchdog();
        window.__aiChat.starting = false;
        try { recognition.stop(); } catch (_) { /* diabaikan */ }
        stopLevelMeter();
        notifyVoice('Tidak dapat memulai perekaman suara. Coba muat ulang halaman.');
    }
};

const stopRecognition = () => {
    clearTimeout(window.__aiChat?.voiceWatchdog);
    clearTimeout(window.__aiChat?.resultWatchdog);

    try {
        recognition?.stop();
    } catch (e) {
        /* diabaikan */
    }

    stopLevelMeter();
};

const bindDelegatedEvents = () => {
    if (window.__aiChat.delegated) {
        return;
    }
    window.__aiChat.delegated = true;

    // Mic: satu listener delegasi untuk seluruh dokumen.
    document.addEventListener('click', (event) => {
        const mic = event.target.closest('#ai-mic-button');
        if (mic) {
            if (mic.disabled) {
                return;
            }

            if (mic.classList.contains('is-listening')) {
                stopRecognition();
            } else {
                startRecognition(readConfig(document.querySelector(AI_ROOT)));
            }
            return;
        }

        if (event.target.closest('#ai-voice-stop')) {
            stopRecognition();
        }
    });

    // Enter mengirim, Shift+Enter menambah baris (delegasi keyboard).
    document.addEventListener('keydown', (event) => {
        const input = event.target.closest('#ai-message-input');
        if (!input) {
            // Escape menutup modal konfirmasi / drawer riwayat (state Livewire).
            if (event.key === 'Escape') {
                const modal = document.querySelector('.ai-modal');

                // Modal selalu ada di DOM; tutup lebih dulu bila sedang terbuka.
                if (modal && ! modal.classList.contains('is-hidden')) {
                    modal.querySelector('.ai-modal__backdrop')?.click();

                    return;
                }

                const drawer = document.querySelector('.ai-history');

                // Drawer selalu ada di DOM; hanya tutup jika sedang terbuka.
                if (drawer && ! drawer.classList.contains('is-hidden')) {
                    drawer.querySelector('.ai-history__backdrop')?.click();
                }
            }

            return;
        }

        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            input.form?.requestSubmit();
        }
    });

    // Auto-grow saat mengetik.
    document.addEventListener('input', (event) => {
        if (event.target.closest('#ai-message-input')) {
            autoGrow(event.target);
        }
    });

    // Bubble optimistik + indikator "mengetik" saat form dikirim.
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('.ai-composer');
        if (!form) {
            return;
        }

        const field = document.getElementById('ai-message-input');
        const text = (field?.value || '').trim();

        if (text) {
            const wrap = document.createElement('div');
            wrap.className = 'ai-bubble ai-bubble--user ai-bubble--pending';

            const role = document.createElement('div');
            role.className = 'ai-bubble__role';
            role.textContent = 'Anda';

            const body = document.createElement('div');
            body.className = 'ai-bubble__content';
            body.textContent = text;

            wrap.append(role, body);
            document.getElementById('ai-chat-scroll')?.appendChild(wrap);
        }

        const typing = document.getElementById('ai-typing');
        if (typing) {
            typing.hidden = false;
        }

        if (field) {
            field.style.height = 'auto';
        }

        setTimeout(scrollToEnd, 20);
    });

    window.addEventListener('resize', () => fitHeight());

    // Lepas penanda padding saat meninggalkan halaman (mode SPA).
    document.addEventListener('livewire:navigate', () => {
        document.querySelector('.fi-main')?.classList.remove('ai-main');
    });

    // TTS opsional saat balasan AI tiba.
    document.addEventListener('livewire:init', () => {
        window.Livewire?.on?.('ai-reply', (payload) => {
            const config = readConfig(document.querySelector(AI_ROOT));
            if (config.autoSpeak) {
                speak(payload?.text ?? '', config);
            }
            setTimeout(scrollToEnd, 50);
        });
    });
};

const setup = () => {
    const page = document.querySelector(AI_ROOT);

    // Tandai <main> agar padding khusus halaman ini bisa diterapkan.
    const main = document.querySelector('.fi-main');
    if (main) {
        main.classList.toggle('ai-main', !!page);
    }

    // Penanda global untuk menyembunyikan footer kredit di halaman ini saja.
    document.documentElement.classList.toggle('ai-page-active', !!page);

    if (!page) {
        return;
    }

    // Pemberitahuan dini bila browser tidak mendukung input suara (mis. Opera).
    const support = voiceSupport();
    if (! support.ok) {
        notifyVoice(VOICE_MESSAGES[support.reason]);
    }

    fitHeight();
    showVoicePanel(false, document.getElementById('ai-mic-button'));
    setTimeout(scrollToEnd, 50);
};

const boot = () => {
    window.__aiChat = window.__aiChat || {};

    bindDelegatedEvents();

    if (!window.__aiChat.hookBound && window.Livewire?.hook) {
        window.__aiChat.hookBound = true;
        window.Livewire.hook('morph.updated', () => {
            document.querySelectorAll('.ai-bubble--pending').forEach((el) => el.remove());
            const t = document.getElementById('ai-typing');
            if (t) {
                t.hidden = true;
            }
            autoGrow(document.getElementById('ai-message-input'));
            setTimeout(scrollToEnd, 50);
        });
    }

    setup();
};

document.addEventListener('DOMContentLoaded', boot);
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:init', boot);

boot();
