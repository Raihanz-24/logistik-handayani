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

const startRecognition = (config) => {
    const mic = document.getElementById('ai-mic-button');

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

    recognition = new SR();
    recognition.lang = mic?.dataset.lang || config.lang;
    recognition.interimResults = true;
    recognition.continuous = false;
    recognition.maxAlternatives = 1;

    finalTranscript = '';

    let started = false;

    const clearWatchdog = () => {
        clearTimeout(window.__aiChat.voiceWatchdog);
    };

    recognition.onstart = () => {
        started = true;
        clearWatchdog();
        showVoicePanel(true, mic);
    };

    recognition.onend = () => {
        clearWatchdog();
        showVoicePanel(false, mic);
    };

    recognition.onaudiostart = () => {
        const preview = document.getElementById('ai-voice-preview');
        if (preview) {
            preview.textContent = 'Mendengarkan suara…';
        }
    };

    recognition.onerror = (event) => {
        showVoicePanel(false, mic);

        const code = event?.error || '';

        if (code === 'not-allowed' || code === 'service-not-allowed') {
            notifyVoice('Akses mikrofon ditolak. Izinkan mikrofon untuk situs ini di pengaturan browser.');
        } else if (code === 'audio-capture') {
            notifyVoice('Mikrofon tidak terdeteksi pada perangkat ini.');
        } else if (code === 'network') {
            notifyVoice('Pengenalan suara butuh koneksi internet yang stabil.');
        } else if (code && code !== 'no-speech' && code !== 'aborted') {
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
        clearWatchdog();
        window.__aiChat.voiceWatchdog = setTimeout(() => {
            if (! started) {
                showVoicePanel(false, mic);
                notifyVoice(
                    'Mikrofon tidak merespons. Pastikan Anda mengizinkan akses mikrofon, lalu coba lagi dengan Chrome atau Edge terbaru.',
                    { sticky: true },
                );
                stopRecognition();
            }
        }, 3000);
    } catch (e) {
        clearWatchdog();
        try { recognition.stop(); } catch (_) { /* diabaikan */ }
        notifyVoice('Tidak dapat memulai perekaman suara. Coba muat ulang halaman.');
    }
};

const stopRecognition = () => {
    clearTimeout(window.__aiChat?.voiceWatchdog);

    try {
        recognition?.stop();
    } catch (e) {
        /* diabaikan */
    }
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
