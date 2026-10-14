/**
 * Asisten AI — perilaku halaman chat penuh (voice, enter-to-send, tinggi, riwayat).
 *
 * Dimuat global lewat render hook (lihat resources/views/filament/ai-assistant.blade.php)
 * supaya tetap hidup saat navigasi SPA Filament (`livewire:navigate`). Semua event
 * dipasang dengan delegasi/sekali-pasang agar tidak menumpuk antar navigasi.
 */

const AI_ROOT = '.ai-page';

/**
 * Preferensi suara (disimpan di localStorage):
 * - append  : hasil mic DITAMBAHKAN ke teks yang sudah ada (tidak menghapus).
 * - autoSend: begitu selesai bicara, pesan langsung dikirim otomatis.
 * - speak   : baca ulang balasan/ringkasan lewat Text-to-Speech (suara).
 */
const VOICE_PREFS_KEY = 'ai_voice_prefs';
const voicePrefs = { append: true, autoSend: false, speak: false };

const loadVoicePrefs = () => {
    try {
        const raw = window.localStorage?.getItem(VOICE_PREFS_KEY);

        if (raw) {
            const parsed = JSON.parse(raw);

            if (typeof parsed.append === 'boolean') {
                voicePrefs.append = parsed.append;
            }

            if (typeof parsed.autoSend === 'boolean') {
                voicePrefs.autoSend = parsed.autoSend;
            }

            if (typeof parsed.speak === 'boolean') {
                voicePrefs.speak = parsed.speak;
            }

            return;
        }
    } catch (e) {
        /* localStorage bisa diblokir; abaikan dan pakai default. */
    }

    // Belum ada preferensi tersimpan: pakai default dari konfigurasi server.
    const root = document.querySelector(AI_ROOT);

    if (root && (root.dataset.aiSpeak || '0') === '1') {
        voicePrefs.speak = true;
    }
};

const saveVoicePrefs = () => {
    try {
        window.localStorage?.setItem(VOICE_PREFS_KEY, JSON.stringify(voicePrefs));
    } catch (e) {
        /* diabaikan */
    }
};

/** Samakan tampilan tombol toggle di header dengan nilai preferensi saat ini. */
const applyVoiceToggleState = () => {
    const append = document.getElementById('ai-toggle-append');
    const autoSend = document.getElementById('ai-toggle-autosend');
    const speakToggle = document.getElementById('ai-toggle-speak');

    if (append) {
        append.classList.toggle('is-on', voicePrefs.append);
        append.setAttribute('aria-pressed', voicePrefs.append ? 'true' : 'false');
    }

    if (autoSend) {
        autoSend.classList.toggle('is-on', voicePrefs.autoSend);
        autoSend.setAttribute('aria-pressed', voicePrefs.autoSend ? 'true' : 'false');
    }

    if (speakToggle) {
        speakToggle.classList.toggle('is-on', voicePrefs.speak);
        speakToggle.setAttribute('aria-pressed', voicePrefs.speak ? 'true' : 'false');
    }
};

const currentFieldValue = () => document.getElementById('ai-message-input')?.value || '';

/** Gabungkan teks lama (base) dengan hasil suara baru sesuai preferensi. */
const joinVoiceText = (base, spoken) => {
    const cleanBase = (base || '').trim();
    const cleanSpoken = (spoken || '').trim();

    if (voicePrefs.append && cleanBase) {
        if (! cleanSpoken) {
            return cleanBase;
        }

        // Beri spasi pemisah agar kalimat tidak menempel.
        return cleanBase.replace(/\s+$/, '') + ' ' + cleanSpoken;
    }

    return cleanSpoken;
};

/** Kirim form composer (dipakai fitur auto-kirim). */
const submitComposer = () => {
    const form = document.querySelector('.ai-composer');
    const field = document.getElementById('ai-message-input');

    if (form && field && field.value.trim() !== '') {
        form.requestSubmit();
    }
};

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
    readSummary: (root?.dataset.aiReadSummary || '1') === '1',
    confirmWords: splitWords(root?.dataset.aiConfirm),
    cancelWords: splitWords(root?.dataset.aiCancel),
    voiceNames: splitWords(root?.dataset.aiVoiceNames),
});

/** Pisahkan daftar kata perintah (dipisah koma) menjadi array huruf kecil. */
const splitWords = (raw) =>
    (raw || '')
        .split(',')
        .map((word) => word.trim().toLowerCase())
        .filter((word) => word !== '');

/** Bersihkan teks ucapan untuk pencocokan perintah (huruf kecil, tanpa tanda baca). */
const normalizeCommandText = (text) =>
    (text || '')
        .toLowerCase()
        .replace(/[^\p{L}\p{N}\s]/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();

/**
 * Cocokkan teks ucapan dengan perintah yang dikonfigurasi.
 * Hanya cocok bila teks PERSIS sebuah perintah (mis. "ya", "simpan", "batal")
 * supaya kalimat biasa tidak salah dianggap perintah.
 *
 * @returns {'confirm'|'cancel'|null}
 */
const matchVoiceCommand = (text, config) => {
    const clean = normalizeCommandText(text);

    if (clean === '') {
        return null;
    }

    if ((config.confirmWords || []).includes(clean)) {
        return 'confirm';
    }

    if ((config.cancelWords || []).includes(clean)) {
        return 'cancel';
    }

    return null;
};

/** Klik tombol konfirmasi/batal pada SATU kartu aksi pending (bila ada & tunggal). */
const triggerPendingAction = (kind) => {
    const cards = document.querySelectorAll('.ai-confirm');

    if (cards.length !== 1) {
        return false;
    }

    const card = cards[0];
    const selector = kind === 'confirm' ? '.ai-confirm__btn--primary' : '.ai-confirm__btn:not(.ai-confirm__btn--primary)';
    const button = card.querySelector(selector);

    if (! button) {
        return false;
    }

    button.click();

    return true;
};

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

/**
 * Daftar voice TTS dengan cache. Chrome memuat voice secara ASINKRON, jadi kita
 * dengar event `voiceschanged` DAN melakukan beberapa kali percobaan ulang,
 * karena di sebagian browser event itu tidak selalu menyala.
 */
let ttsVoices = [];
let ttsVoicesReady = false;

const refreshTtsVoices = () => {
    if (! ('speechSynthesis' in window)) {
        return;
    }

    try {
        const voices = window.speechSynthesis.getVoices();

        if (Array.isArray(voices) && voices.length > 0) {
            ttsVoices = voices;
            ttsVoicesReady = true;
        }
    } catch (e) {
        /* diabaikan */
    }
};

if ('speechSynthesis' in window) {
    refreshTtsVoices();

    // Voice dimuat asinkron di Chrome/Edge; muat ulang saat siap.
    try {
        window.speechSynthesis.addEventListener('voiceschanged', refreshTtsVoices);
    } catch (e) {
        /* fallback di bawah */
    }

    window.speechSynthesis.onvoiceschanged = refreshTtsVoices;

    // Cadangan: beberapa browser tidak menyala-kan `voiceschanged` dengan andal.
    let voiceTries = 0;
    const voiceTimer = setInterval(() => {
        refreshTtsVoices();

        if (ttsVoicesReady || ++voiceTries >= 12) {
            clearInterval(voiceTimer);
        }
    }, 250);
}

/** Apakah voice sudah termuat (dengan sedikit usaha memuat ulang). */
const ttsReady = () => {
    if (! ttsVoicesReady) {
        refreshTtsVoices();
    }

    return ttsVoicesReady;
};

/** Normalisasi kode bahasa: "id-ID" -> "id". */
const langBase = (lang) => (lang || '').toLowerCase().split(/[-_]/)[0];

/**
 * Pilih voice TTS terbaik untuk bahasa yang diminta.
 *
 * Urutan prioritas:
 * 1. Voice yang namanya diinginkan (config.voiceNames), cocok sebagian.
 * 2. Voice dengan kode bahasa persis (mis. "id-ID").
 * 3. Voice dengan bahasa dasar sama (mis. "id").
 * 4. Voice bawaan berkualitas (Google/Microsoft) untuk bahasa itu.
 *
 * Bila tidak ada voice bahasa Indonesia sama sekali, kembalikan null supaya
 * pemanggil bisa memberi tahu pengguna (agar tidak dibacakan logat Inggris).
 */
const pickVoice = (lang, preferred = []) => {
    const pool = ttsVoicesReady ? ttsVoices : (window.speechSynthesis?.getVoices?.() || []);
    const target = langBase(lang);

    if (! Array.isArray(pool) || pool.length === 0) {
        return null;
    }

    const lower = (value) => (value || '').toLowerCase();

    // Batasi ke voice yang berbahasa sama dengan yang diminta (mis. "id").
    // Ini penting agar tidak mengambil voice bahasa lain.
    const sameLang = pool.filter((voice) => langBase(voice.lang) === target);

    if (sameLang.length === 0) {
        return null;
    }

    // 1. Voice pilihan eksplisit (nama mengandung salah satu kata di config).
    for (const want of preferred) {
        const needle = lower(want).trim();

        if (needle === '') {
            continue;
        }

        const found = sameLang.find((voice) => lower(voice.name).includes(needle));

        if (found) {
            return found;
        }
    }

    // 2. Di antara voice sebahasa, utamakan yang paling natural:
    //    "natural"/"neural"/"online" (Microsoft Edge Natural, Google) lebih
    //    halus daripada voice SAPI/eSpeak yang kaku.
    const natural = sameLang.find((voice) => /natural|neural|online/i.test(voice.name));

    if (natural) {
        return natural;
    }

    // 3. Google umumnya paling natural di Chrome.
    const google = sameLang.find((voice) => /google/i.test(voice.name));

    if (google) {
        return google;
    }

    // 4. Voice daring (network) biasanya lebih halus daripada lokal/kaku.
    const online = sameLang.find((voice) => voice.localService === false);

    if (online) {
        return online;
    }

    // 5. Kode bahasa persis (mis. "id-ID"), lalu apa pun yang sebahasa.
    const exact = sameLang.find((voice) => lower(voice.lang).replace('_', '-') === lower(lang));

    return exact || sameLang[0];
};

/**
 * Bacakan teks dengan voice Bahasa Indonesia yang paling natural.
 * Mengembalikan true bila benar-benar diucapkan.
 */
const speak = (text, config, forcedVoice = null) => {
    if (!text || ! ('speechSynthesis' in window)) {
        return false;
    }

    const synth = window.speechSynthesis;
    const lang = config.lang || 'id-ID';
    const voice = forcedVoice || pickVoice(lang, config.voiceNames || []);

    try {
        const utter = new SpeechSynthesisUtterance(text);
        utter.lang = voice?.lang || lang;

        if (voice) {
            utter.voice = voice;
        }

        // Sedikit lebih lambat + pitch natural agar tidak terdengar kaku.
        utter.rate = Math.min(1.2, Math.max(0.7, (config.rate || 1) * 0.95));
        utter.pitch = 1;
        utter.volume = 1;

        // Hanya batalkan bila memang ada antrean. cancel() tepat sebelum speak()
        // bisa ikut membatalkan ucapan baru di sebagian browser (Android).
        if (synth.speaking || synth.pending) {
            synth.cancel();
        }

        // Chromium di Android kadang butuh resume() agar tidak diam.
        try {
            synth.resume();
        } catch (e) {
            /* diabaikan */
        }

        synth.speak(utter);

        return true;
    } catch (e) {
        return false;
    }
};

/** Perangkat sentuh/mobile: kebijakan TTS lebih ketat (wajib dari gesture). */
const isMobileSpeech = () => {
    const ua = navigator.userAgent || '';

    return /Android|iPhone|iPad|iPod|Mobile|Opera Mini|IEMobile/i.test(ua);
};

/**
 * "Bangunkan" mesin TTS pada interaksi pertama. Di Android, speechSynthesis
 * baru diizinkan berbunyi setelah ada gesture pengguna; tanpa ini, pembacaan
 * yang dipicu event lain (mis. balasan AI) akan senyap.
 */
let speechPrimed = false;

const primeSpeech = () => {
    if (speechPrimed || ! ('speechSynthesis' in window)) {
        return;
    }

    speechPrimed = true;

    try {
        // Utterance kosong (nyaris tak terdengar) sekadar membuka izin audio.
        const warmup = new SpeechSynthesisUtterance(' ');
        warmup.volume = 0;
        warmup.rate = 1;
        window.speechSynthesis.speak(warmup);
    } catch (e) {
        /* diabaikan */
    }
};

/** Ringkasan voice yang tersedia, untuk diagnostik di konsol. */
const describeVoices = () => ttsVoices
    .map((voice) => `${voice.name} [${voice.lang}]${voice.localService === false ? ' (online)' : ''}`)
    .join(' | ');

/**
 * Bacakan teks dengan voice Bahasa Indonesia.
 *
 * - Bila ada voice id eksplisit -> pakai itu.
 * - Di MOBILE: ucapkan langsung memakai `lang` (engine TTS Android/Google
 *   mengikuti lang) — jangan ditunda, karena TTS di HP wajib dipicu dari gesture.
 * - Di DESKTOP: bila voice belum termuat, tunggu sebentar lalu coba lagi; bila
 *   sudah termuat tapi tak ada voice id, beri tahu pengguna sekali (agar tidak
 *   dibacakan dengan logat asing).
 */
let lastVoiceWarned = false;

const speakIndonesian = (text, config) => {
    if (! text || ! ('speechSynthesis' in window)) {
        return false;
    }

    refreshTtsVoices();

    const lang = config.lang || 'id-ID';
    const voice = pickVoice(lang, config.voiceNames || []);

    if (voice) {
        lastVoiceWarned = false;

        return speak(text, config, voice);
    }

    // Mobile: andalkan engine TTS sistem yang mengikuti `lang`.
    if (isMobileSpeech()) {
        return speak(text, config, null);
    }

    if (! ttsReady()) {
        // Desktop: voice dimuat asinkron; tunggu & coba ulang (maks. ~3 detik).
        speakIndonesian.pending = (speakIndonesian.pending || 0) + 1;

        if (speakIndonesian.pending <= 12) {
            setTimeout(() => speakIndonesian(text, config), 250);

            return false;
        }
    }

    speakIndonesian.pending = 0;

    if (! lastVoiceWarned) {
        lastVoiceWarned = true;
        console.warn('[Asisten AI] Voice TTS tersedia: ' + (describeVoices() || '(belum ada)'));
        notifyVoice(
            'Suara Bahasa Indonesia belum tersedia di perangkat ini. Pasang paket suara Indonesia (Windows: Pengaturan > Waktu & bahasa > Ucapan; atau buka lewat Microsoft Edge yang punya voice "Natural").',
            { sticky: true },
        );
    }

    return false;
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

    if (state && preview) {
        // Jangan reset teks saat mode KUNCI (mikrofon menyala ulang tiap frasa),
        // supaya hasil transkrip sebelumnya tidak hilang dari panel.
        if (! voiceSession.locked) {
            preview.textContent = 'Mulai bicara sekarang';
        }

        // Reset penanda "sudah menangkap suara" setiap sesi baru.
        delete preview.dataset.heard;
    }

    // Status tombol mic dikelola terpusat oleh applyMicState().
    if (mic) {
        applyMicState(mic);
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

    if (!field) {
        return;
    }

    text = text || '';

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

/**
 * Status sesi suara. Mendukung dua mode:
 * - TAHAN (push-to-talk): tahan tombol mic -> bicara -> lepas untuk berhenti.
 * - KUNCI: geser tombol mic ke atas saat menahan (atau tap singkat) -> mikrofon
 *   tetap hidup sampai ditekan lagi tombol mic (atau tombol Batal membuangnya).
 */
const voiceSession = {
    intent: false,
    locked: false,
    gotResult: false,
    error: '',
    cancelled: false,
};

const isVoiceActive = () => voiceSession.intent === true;

/** Samakan tampilan tombol mic dengan status sesi (aktif/terkunci). */
const applyMicState = (micButton) => {
    const mic = micButton || document.getElementById('ai-mic-button');

    if (! mic) {
        return;
    }

    const on = isVoiceActive();

    mic.classList.toggle('is-listening', on);
    mic.classList.toggle('is-locked', on && voiceSession.locked);
    mic.setAttribute('aria-pressed', on ? 'true' : 'false');
    mic.setAttribute(
        'title',
        on
            ? (voiceSession.locked
                ? 'Mikrofon terkunci — tekan Batal untuk membuang rekaman'
                : 'Lepas untuk kirim, geser ke atas untuk mengunci')
            : 'Tahan untuk bicara (geser ke atas untuk mengunci)',
    );
};

/** Tampilkan/sembunyikan petunjuk "geser untuk mengunci" di atas tombol mic. */
const showLockHint = (state) => {
    const hint = document.getElementById('ai-lock-hint');

    if (hint) {
        hint.hidden = ! state;
    }
};

const toggleVoiceLock = (locked) => {
    voiceSession.locked = locked === true;

    const hint = document.getElementById('ai-lock-hint');
    const title = document.querySelector('#ai-voice-panel .ai-voice__title');
    const text = hint?.querySelector('.ai-lock-hint__text');

    if (title) {
        title.textContent = voiceSession.locked ? 'Terkunci — tekan Batal untuk membuang' : 'Mendengarkan…';
    }

    if (hint) {
        hint.classList.toggle('is-armed', voiceSession.locked);
    }

    if (text) {
        text.textContent = voiceSession.locked ? 'Mikrofon terkunci' : 'Geser ke atas untuk mengunci';
    }

    applyMicState();
};

/** Mulai sesi suara baru (intent=true). Pemanggil sudah memvalidasi dukungan browser. */
const beginVoiceSession = (micButton) => {
    if (isVoiceActive()) {
        return;
    }

    voiceSession.intent = true;
    voiceSession.locked = false;
    voiceSession.gotResult = false;
    voiceSession.error = '';
    voiceSession.attempts = 0;
    voiceSession.startedAt = Date.now();
    voiceSession.finalized = false;
    voiceSession.commandMatched = false;

    // "Tambahkan" = pertahankan teks lama lalu sambung hasil mic.
    // "Ganti" = mulai dari kosong (hapus teks sebelumnya).
    voiceSession.baseText = voicePrefs.append ? currentFieldValue().trim() : '';

    finalTranscript = '';

    applyMicState(micButton);
};

/** Akhiri sesi suara total: bersihkan timer, meter, dan UI. */
const endVoiceSession = (micButton) => {
    // Idempoten: onend bisa terpanggil lagi setelah stop(), jadi pastikan
    // auto-kirim/pembatalan hanya dijalankan SEKALI per sesi.
    if (voiceSession.finalized) {
        return;
    }

    voiceSession.finalized = true;

    clearTimeout(window.__aiChat?.voiceWatchdog);
    clearTimeout(window.__aiChat?.resultWatchdog);
    clearTimeout(window.__aiChat?.rearmTimer);
    window.__aiChat.starting = false;

    const wasActive = isVoiceActive();
    const hadResult = voiceSession.gotResult === true;
    const hadError = !! voiceSession.error;
    const cancelled = voiceSession.cancelled === true;
    const commandMatched = voiceSession.commandMatched === true;

    voiceSession.intent = false;
    voiceSession.locked = false;
    voiceSession.active = false;

    showLockHint(false);

    const hint = document.getElementById('ai-lock-hint');
    if (hint) {
        hint.classList.remove('is-armed');
    }

    // Diagnosa SEBELUM meter dihentikan (butuh data peak/analyser). Lewati bila
    // sesi terlalu singkat (tap tak sengaja), dibatalkan, atau perintah suara.
    const tooShort = (Date.now() - (voiceSession.startedAt || 0)) < 600;

    if (wasActive && ! hadResult && ! hadError && ! tooShort && ! cancelled && ! commandMatched) {
        diagnoseEmptyResult();
    }

    stopLevelMeter();
    showVoicePanel(false, micButton);
    applyMicState(micButton);

    if (cancelled) {
        // BATALKAN: buang hasil suara (kembalikan ke teks sebelum merekam),
        // dan JANGAN kirim meski auto-kirim menyala. Bila mode "Tambah" aktif,
        // teks yang sudah ada sebelum merekam tetap dipertahankan.
        syncTranscriptToField(voiceSession.baseText || '');
    } else if (commandMatched) {
        // Perintah suara sudah dijalankan (konfirmasi/batal); jangan kirim teks.
        syncTranscriptToField(voiceSession.baseText || '');
    } else if (hadResult && ! hadError && voicePrefs.autoSend) {
        // Auto-kirim: bila aktif dan ada hasil suara (tanpa error), langsung kirim.
        // Pastikan teks final tersinkron ke state Livewire sebelum submit.
        if (voiceSession.lastCombined) {
            syncTranscriptToField(voiceSession.lastCombined);
        }

        submitComposer();
    }

    voiceSession.gotResult = false;
    voiceSession.error = '';
    voiceSession.lastCombined = '';
    voiceSession.baseText = '';
    voiceSession.cancelled = false;
    voiceSession.commandMatched = false;
};

/**
 * Tombol merah: GAGALKAN rekaman. Hentikan mikrofon, hapus hasil suara
 * (kembalikan teks ke sebelum merekam), dan jangan kirim apa pun.
 */
const cancelVoiceSession = () => {
    if (! isVoiceActive()) {
        return;
    }

    voiceSession.cancelled = true;
    voiceSession.intent = false;
    voiceSession.locked = false;

    try {
        recognition?.stop();
    } catch (e) {
        /* diabaikan */
    }

    endVoiceSession(document.getElementById('ai-mic-button'));
};

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
    // Mode KUNCI menyalakan ulang recognition tiap frasa; jangan membuat stream
    // getUserMedia baru bila meter sudah berjalan (menghindari kebocoran perangkat).
    if (micCapture.stream && micCapture.analyser) {
        return;
    }

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

    if (isVoiceActive()) {
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

    beginVoiceSession(micButton);
    armRecognition(micButton, config);
};

/**
 * Menyiapkan & memulai satu objek SpeechRecognition. Dipanggil ulang otomatis
 * pada mode KUNCI setiap kali mesin berhenti karena jeda bicara (continuous=false).
 */
const armRecognition = (micButton, config) => {
    if (window.__aiChat.starting) {
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

    window.__aiChat.starting = true;

    let started = false;

    const clearWatchdog = () => {
        clearTimeout(window.__aiChat.voiceWatchdog);
        clearTimeout(window.__aiChat.resultWatchdog);
    };

    recognition.onstart = () => {
        started = true;
        clearWatchdog();
        showVoicePanel(true, micButton);
        showLockHint(! voiceSession.locked);
        noteVoiceDiagnostic('start', { lang: recognition.lang });

        // Ukur level mikrofon secara paralel (opsional & non-blocking) untuk
        // memberi diagnosa tepat bila tidak ada teks sama sekali.
        beginMetering();

        // Watchdog "ada audio tapi tak ada teks": umum terjadi bila layanan
        // pengenalan Chrome tidak mengembalikan hasil (mis. jaringan terblokir).
        window.__aiChat.resultWatchdog = setTimeout(() => {
            if (! voiceSession.gotResult) {
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

        // Selama sesi masih diinginkan (masih ditahan atau sedang terkunci),
        // nyalakan lagi otomatis. Ini penting karena `continuous` sering
        // diabaikan Chrome — terutama di Android — sehingga mesin berhenti
        // sendiri saat pengguna berjeda di tengah kalimat.
        if (voiceSession.intent) {
            voiceSession.attempts = (voiceSession.attempts || 0) + 1;

            if (voiceSession.attempts > 240) {
                endVoiceSession(micButton);

                return;
            }

            window.__aiChat.starting = false;

            // Beri jeda kecil agar tidak membebani CPU & mic tidak "berebut".
            clearTimeout(window.__aiChat.rearmTimer);
            window.__aiChat.rearmTimer = setTimeout(() => {
                if (voiceSession.intent) {
                    armRecognition(micButton, config);
                }
            }, 180);

            return;
        }

        endVoiceSession(micButton);
    };

    recognition.onerror = (event) => {
        const code = event?.error || '';

        if (code !== 'aborted') {
            noteVoiceDiagnostic('error', { codes: code });
        }

        // Selama sesi masih diinginkan, jeda bicara memicu 'no-speech'/'aborted'
        // — bukan kegagalan. Abaikan; onend akan menyalakan ulang mesin.
        if (voiceSession.intent && (code === 'no-speech' || code === 'aborted')) {
            return;
        }

        voiceSession.error = code || 'error';
        window.__aiChat.starting = false;
        showVoicePanel(false, micButton);
        stopLevelMeter();
        endVoiceSession(micButton);

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

        // Perintah suara cepat: bila ucapan PERSIS "ya/simpan/batal" dan ada
        // tepat satu kartu konfirmasi, jalankan tanpa menyentuh kotak pesan.
        if (final) {
            const command = matchVoiceCommand(finalTranscript, config);

            if (command && triggerPendingAction(command === 'confirm' ? 'confirm' : 'cancel')) {
                voiceSession.commandMatched = true;
                stopRecognition();

                return;
            }
        }

        // Hasil suara digabung dengan teks lama bila toggle "tambahkan" aktif.
        const spoken = (finalTranscript || interim).trim();
        const combined = joinVoiceText(voiceSession.baseText, spoken);

        if (spoken) {
            voiceSession.gotResult = true;
            voiceSession.lastCombined = combined;
        }

        const preview = document.getElementById('ai-voice-preview');

        if (combined && preview) {
            preview.textContent = combined;
        }

        if (final) {
            // Hanya sinkronkan ke Livewire saat hasil final agar tidak spam request.
            syncTranscriptToField(combined);
        } else if (spoken) {
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
                endVoiceSession(micButton);
                notifyVoice(
                    'Mikrofon tidak merespons. Pastikan Anda mengizinkan akses mikrofon, lalu coba lagi dengan Chrome atau Edge terbaru.',
                    { sticky: true },
                );
            }
        }, 3000);
    } catch (e) {
        clearWatchdog();
        endVoiceSession(micButton);
        notifyVoice('Tidak dapat memulai perekaman suara. Coba muat ulang halaman.');
    }
};

/**
 * Akhiri sesi suara. Bila sesi BELUM terkunci, teks hasil tetap berada di kotak
 * pesan dan TIDAK dikirim otomatis (pengguna bisa menyunting dulu).
 */
const stopRecognition = () => {
    const micButton = document.getElementById('ai-mic-button');

    clearTimeout(window.__aiChat?.voiceWatchdog);
    clearTimeout(window.__aiChat?.resultWatchdog);

    // Matikan intent lebih dulu agar onend tidak menyalakan ulang (mode kunci).
    voiceSession.intent = false;
    voiceSession.locked = false;

    try {
        recognition?.stop();
    } catch (e) {
        /* diabaikan */
    }

    endVoiceSession(micButton);
};

/**
 * Bacakan ringkasan kartu konfirmasi yang baru muncul (bila toggle "Suara" aktif
 * dan konfigurasi read_summary mengizinkan). Ini membuat alur belanja hands-free:
 * pengguna cukup mendengar ringkasan lalu ucapkan "ya" / "batal".
 */
const speakPendingSummary = () => {
    const config = readConfig(document.querySelector(AI_ROOT));

    if (! voicePrefs.speak || ! config.readSummary) {
        return;
    }

    const cards = document.querySelectorAll('.ai-confirm');
    const summary = cards.length === 1
        ? (cards[0].querySelector('.ai-confirm__summary')?.textContent || '').trim()
        : '';

    if (summary === '') {
        return;
    }

    speakIndonesian('Konfirmasi: ' + summary + '. Ucapkan ya untuk menyimpan, atau batal.', config);
};

const bindDelegatedEvents = () => {
    if (window.__aiChat.delegated) {
        return;
    }
    window.__aiChat.delegated = true;

    // Mic: satu listener delegasi untuk seluruh dokumen.
    // - Pointer (mobile & desktop): TAHAN untuk bicara, LEPAS untuk berhenti;
    //   geser ke atas saat menahan untuk MENGUNCI (mic tetap hidup sampai Stop).
    // - Klik keyboard (event.detail === 0): toggle biasa untuk aksesibilitas.
    document.addEventListener('pointerdown', (event) => {
        // Buka izin audio TTS pada interaksi pertama (wajib di Android).
        primeSpeech();

        const mic = event.target.closest('#ai-mic-button');

        if (! mic || mic.disabled) {
            return;
        }

        // Sudah terkunci: klik lagi = berhenti (ditangani di pointerup).
        if (isVoiceActive() && voiceSession.locked) {
            voiceSession.pointerActive = true;
            voiceSession.pointerId = event.pointerId;
            voiceSession.wasLocked = true;

            return;
        }

        voiceSession.pointerActive = true;
        voiceSession.wasLocked = false;
        voiceSession.pointerId = event.pointerId;
        voiceSession.downY = event.clientY;
        voiceSession.downAt = Date.now();
        voiceSession.dragLock = false;

        startRecognition(readConfig(document.querySelector(AI_ROOT)));
    });

    document.addEventListener('pointermove', (event) => {
        if (! voiceSession.pointerActive || event.pointerId !== voiceSession.pointerId || voiceSession.wasLocked) {
            return;
        }

        if (! isVoiceActive() || voiceSession.locked) {
            return;
        }

        const micButton = document.getElementById('ai-mic-button');

        // Geser ke atas minimal 60px = indikasi mau mengunci; tampilkan petunjuk.
        voiceSession.dragLock = event.clientY < (voiceSession.downY ?? event.clientY) - 60;

        if (micButton) {
            micButton.classList.toggle('is-arming', voiceSession.dragLock);
        }
    });

    const finishPointer = (event) => {
        if (! voiceSession.pointerActive || (event && event.pointerId !== voiceSession.pointerId)) {
            return;
        }

        voiceSession.pointerActive = false;

        const micButton = document.getElementById('ai-mic-button');

        if (micButton) {
            micButton.classList.remove('is-arming');
        }

        // Sudah terkunci, dan tadi tombol ditekan -> berhenti.
        if (voiceSession.wasLocked) {
            voiceSession.wasLocked = false;
            stopRecognition();

            return;
        }

        if (voiceSession.dragLock) {
            // Geser ke atas -> KUNCI: mikrofon tetap hidup sampai tombol Stop.
            voiceSession.dragLock = false;
            toggleVoiceLock(true);

            return;
        }

        // Tap singkat (klik desktop / ketuk cepat) -> anggap sebagai "nyalakan &
        // kunci", supaya perilaku klik-lama (mulai/berhenti) tetap terasa.
        // Tahan lebih lama lalu lepas -> berhenti (push-to-talk).
        const quickTap = (Date.now() - (voiceSession.downAt || 0)) < 250;

        if (quickTap && isVoiceActive() && ! voiceSession.gotResult) {
            toggleVoiceLock(true);

            return;
        }

        // Lepas tanpa geser -> berhenti.
        stopRecognition();
    };

    document.addEventListener('pointerup', finishPointer);
    document.addEventListener('pointercancel', finishPointer);

    // Aksesibilitas: aktifkan via keyboard (Enter/Space pada tombol saat fokus).
    document.addEventListener('click', (event) => {
        // Buka izin audio TTS (mobile) pada interaksi pertama via klik keyboard.
        primeSpeech();

        const mic = event.target.closest('#ai-mic-button');

        if (mic) {
            if (mic.disabled) {
                return;
            }

            // Hanya untuk "click" dari keyboard (detail === 0); klik mouse/tap
            // sudah ditangani pointerdown/up, jadi jangan dobel.
            if (event.detail !== 0) {
                return;
            }

            if (isVoiceActive()) {
                stopRecognition();
            } else {
                startRecognition(readConfig(document.querySelector(AI_ROOT)));
            }

            return;
        }

        if (event.target.closest('#ai-voice-stop')) {
            // Tombol merah = GAGALKAN rekaman (hentikan + hapus, jangan kirim).
            cancelVoiceSession();

            return;
        }

        // Toggle: hasil suara ditambahkan ke teks lama (bukan menghapus).
        if (event.target.closest('#ai-toggle-append')) {
            voicePrefs.append = ! voicePrefs.append;
            saveVoicePrefs();
            applyVoiceToggleState();

            return;
        }

        // Toggle: kirim pesan otomatis setelah selesai bicara.
        if (event.target.closest('#ai-toggle-autosend')) {
            voicePrefs.autoSend = ! voicePrefs.autoSend;
            saveVoicePrefs();
            applyVoiceToggleState();

            return;
        }

        // Toggle: baca ulang balasan AI lewat suara (Text-to-Speech).
        if (event.target.closest('#ai-toggle-speak')) {
            voicePrefs.speak = ! voicePrefs.speak;
            saveVoicePrefs();
            applyVoiceToggleState();

            const config = readConfig(document.querySelector(AI_ROOT));

            if (voicePrefs.speak) {
                // Umpan balik singkat bahwa suara aktif (voice Indonesia).
                speakIndonesian('Suara aktif.', config);
            } else if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            }
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

    // Kunci scroll halaman pada Asisten AI: gulir hanya diizinkan di area
    // daftar chat dan panel riwayat. Ini memastikan kolom isi pesan tetap
    // menempel di bawah (di atas bottom navbar) dan tidak ikut bergeser.
    document.addEventListener('touchmove', (event) => {
        if (! document.querySelector(AI_ROOT)) {
            return;
        }

        // Area yang memang boleh bergulir sendiri.
        if (event.target.closest('#ai-chat-scroll, .ai-history__panel')) {
            return;
        }

        // JANGAN blokir gerakan jari di atas kontrol interaktif (tombol toggle
        // header, tombol mic, kolom tulis, dll). Tap di layar sentuh hampir selalu
        // disertai gerakan jari kecil; bila touchmove di-preventDefault, event
        // `click` ikut batal sehingga tombol terasa "tidak bisa ditekan" di HP.
        if (event.target.closest('button, a, input, textarea, select, label, summary, [role="button"], [contenteditable="true"], .ai-chat__bar, .ai-composer, .ai-voice, .ai-confirm, .ai-toggle')) {
            return;
        }

        // Sisa halaman (area non-interaktif): kunci gulir agar composer tetap
        // menempel di atas bottom navbar.
        event.preventDefault();
    }, { passive: false });

    // Lepas penanda padding saat meninggalkan halaman (mode SPA).
    document.addEventListener('livewire:navigate', () => {
        document.querySelector('.fi-main')?.classList.remove('ai-main');
    });

    // TTS opsional saat balasan AI tiba.
    document.addEventListener('livewire:init', () => {
        window.Livewire?.on?.('ai-reply', (payload) => {
            const config = readConfig(document.querySelector(AI_ROOT));
            const text = payload?.text ?? '';

            // Bila ada kartu konfirmasi, biarkan speakPendingSummary() yang membacakan
            // ringkasan (agar tidak dobel suara).
            const hasPendingCard = document.querySelectorAll('.ai-confirm').length > 0;

            // Baca ulang balasan bila toggle "Suara" aktif.
            if (voicePrefs.speak && text && ! hasPendingCard) {
                speakIndonesian(text, config);
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

    // Reset state sesi suara saat (re)masuk halaman (mis. lewat SPA navigation).
    voiceSession.intent = false;
    voiceSession.locked = false;
    voiceSession.active = false;
    voiceSession.pointerActive = false;
    voiceSession.wasLocked = false;

    const micButton = document.getElementById('ai-mic-button');

    voiceSession.downY = null;
    voiceSession.dragLock = false;

    showLockHint(false);
    applyMicState(micButton);
    applyVoiceToggleState();
    showVoicePanel(false, micButton);
    setTimeout(scrollToEnd, 50);
    setTimeout(speakPendingSummary, 400);
};

const boot = () => {
    window.__aiChat = window.__aiChat || {};

    loadVoicePrefs();

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
            speakPendingSummary();
        });
    }

    setup();
};

document.addEventListener('DOMContentLoaded', boot);
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:init', boot);

boot();
