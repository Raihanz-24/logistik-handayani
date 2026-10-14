<x-filament-panels::page class="ai-page-wrap">
    @php($messages = $this->messages)
    @php($conversations = $this->recentConversations)
    @php($pendingActions = $this->pendingActions)
    @php($configured = $this->isConfigured)
    @php($voiceLang = config('ai.voice.lang', 'id-ID'))
    @php($voiceRate = (float) config('ai.voice.speech_rate', 1.0))
    @php($autoSpeak = (bool) config('ai.voice.auto_speak', false))

    <div
        class="ai-page"
        data-ai-lang="{{ $voiceLang }}"
        data-ai-rate="{{ $voiceRate }}"
        data-ai-speak="{{ $autoSpeak ? '1' : '0' }}"
    >
        @unless ($configured)
            <div class="ai-alert">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                <span>Asisten AI belum dikonfigurasi. Isi <code>AI_API_KEY</code> (dan pastikan <code>AI_ENABLED=true</code>) di berkas <code>.env</code>.</span>
            </div>
        @endunless

        <section class="ai-chat">
            <header class="ai-chat__bar">
                <div class="ai-chat__identity">
                    <span class="ai-chat__avatar"><x-filament::icon icon="heroicon-o-sparkles" /></span>
                    <span class="ai-chat__meta">
                        <strong>Asisten Logistik</strong>
                        <small>by kafeinxcode</small>
                    </span>
                </div>

                <div class="ai-chat__bar-actions">
                    <button type="button" class="ai-iconbtn" wire:click="startNewChat" title="Mulai percakapan baru" aria-label="Mulai percakapan baru">
                        <x-filament::icon icon="heroicon-m-plus" />
                    </button>
                    <button type="button" class="ai-iconbtn" wire:click="toggleHistory" title="Riwayat percakapan" aria-label="Riwayat percakapan">
                        <x-filament::icon icon="heroicon-m-clock" />
                    </button>
                </div>
            </header>

            <div class="ai-chat__scroll" id="ai-chat-scroll">
                @if ($messages->isEmpty())
                    <div class="ai-empty">
                        <span class="ai-empty__icon"><x-filament::icon icon="heroicon-o-chat-bubble-left-right" /></span>
                        <strong>Mulai percakapan</strong>
                        <span>Contoh: "Berapa stok galon di gudang utama?" atau "Catat belanja 5 galon ke supplier X hari ini".</span>
                        <div class="ai-chat__suggestions">
                            @foreach (\App\Filament\Pages\AsistenAi::SUGGESTIONS as $index => $suggestion)
                                <button type="button" class="ai-chip" wire:click="useSuggestion({{ $index }})">
                                    {{ $suggestion }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    @foreach ($messages as $chatMessage)
                        <div
                            class="ai-bubble ai-bubble--{{ $chatMessage->role }}"
                            wire:key="msg-{{ $chatMessage->id }}"
                        >
                            <div class="ai-bubble__role">
                                {{ $chatMessage->role === 'user' ? 'Anda' : 'Asisten' }}
                            </div>
                            <div class="ai-bubble__content">{!! nl2br(e($chatMessage->content)) !!}</div>
                        </div>
                    @endforeach
                @endif

                <div class="ai-bubble ai-bubble--assistant ai-bubble--typing" id="ai-typing" wire:key="msg-typing" hidden>
                    <div class="ai-bubble__role">Asisten</div>
                    <div class="ai-bubble__content">
                        <span class="ai-dot"></span><span class="ai-dot"></span><span class="ai-dot"></span>
                        <span class="ai-typing-text">sedang menyusun jawaban…</span>
                    </div>
                </div>

                @foreach ($pendingActions as $action)
                    <div class="ai-confirm" wire:key="action-{{ $action->id }}">
                        <div class="ai-confirm__head">
                            <span class="ai-confirm__icon"><x-filament::icon icon="heroicon-m-shield-exclamation" /></span>
                            <span>{{ $action->type === 'mutasi_draft' ? 'Konfirmasi draft mutasi' : 'Konfirmasi transaksi belanja' }}</span>
                        </div>
                        <p class="ai-confirm__summary">{{ $action->summary }}</p>
                        <div class="ai-confirm__actions">
                            <button
                                type="button"
                                class="ai-confirm__btn ai-confirm__btn--primary"
                                wire:click="confirmAction({{ (int) $action->id }})"
                                wire:loading.attr="disabled"
                                wire:target="confirmAction({{ (int) $action->id }})"
                            >
                                <span wire:loading.remove wire:target="confirmAction({{ (int) $action->id }})" class="ai-confirm__btn-label">
                                    <x-filament::icon icon="heroicon-m-check" />
                                    {{ $action->type === 'mutasi_draft' ? 'Buat Draft' : 'Catat Transaksi' }}
                                </span>
                                <span wire:loading wire:target="confirmAction({{ (int) $action->id }})" class="ai-confirm__spinner" aria-hidden="true"></span>
                            </button>
                            <button
                                type="button"
                                class="ai-confirm__btn"
                                wire:click="cancelAction({{ (int) $action->id }})"
                                wire:loading.attr="disabled"
                                wire:target="cancelAction({{ (int) $action->id }})"
                            >
                                <x-filament::icon icon="heroicon-m-x-mark" />
                                Batalkan
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="ai-voice" id="ai-voice-panel" hidden>
                <div class="ai-voice__wave" id="ai-voice-wave" aria-hidden="true">
                    <span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                </div>
                <div class="ai-voice__body">
                    <strong class="ai-voice__title">Mendengarkan…</strong>
                    <span class="ai-voice__preview" id="ai-voice-preview">Mulai bicara sekarang</span>
                </div>
                <button type="button" class="ai-voice__stop" id="ai-voice-stop" aria-label="Berhenti merekam">
                    <x-filament::icon icon="heroicon-m-stop" />
                    <span>Stop</span>
                </button>
            </div>

            <form class="ai-composer" wire:submit="sendMessage">
                <button
                    type="button"
                    id="ai-mic-button"
                    class="ai-mic"
                    title="Bicara (input suara)"
                    aria-label="Bicara (input suara)"
                    data-lang="{{ $voiceLang }}"
                    @disabled(! $configured)
                >
                    <x-filament::icon icon="heroicon-m-microphone" />
                </button>

                <textarea
                    id="ai-message-input"
                    class="ai-composer__input"
                    rows="1"
                    placeholder="Tulis pesan… (Enter untuk kirim)"
                    wire:model="message"
                    @disabled(! $configured)
                ></textarea>

                <button
                    type="submit"
                    class="ai-send"
                    title="Kirim"
                    aria-label="Kirim"
                    wire:loading.attr="disabled"
                    wire:target="sendMessage"
                    @disabled(! $configured)
                >
                    <span wire:loading.remove wire:target="sendMessage"><x-filament::icon icon="heroicon-m-paper-airplane" /></span>
                    <span wire:loading wire:target="sendMessage" class="ai-send__loading"></span>
                </button>
            </form>

            <div id="ai-voice-hint" class="ai-voice-hint" role="status" hidden></div>
        </section>

        {{-- Panel riwayat: drawer di kanan untuk mobile & desktop.
             Selalu ada di DOM; hanya kelas is-hidden yang ditoggle dari state
             Livewire ($historyOpen). CATATAN PENTING: file ini TIDAK boleh
             diawali BOM. BOM membuat Livewire menempelkan wire:id ke <section>
             dalam, bukan ke <div class="fi-page"> pembungkusnya, sehingga tiap
             morph mengganti root komponen dan semua tombol halaman berhenti
             bekerja. Lihat tes test_berkas_asisten_tidak_memiliki_bom(). --}}
        <div class="ai-history {{ $historyOpen ? '' : 'is-hidden' }}" wire:key="ai-history">
            <div class="ai-history__backdrop" wire:click="closeHistory"></div>
            <aside class="ai-history__panel">
                    <div class="ai-history__head">
                        <strong>Riwayat</strong>
                        <div class="ai-history__head-actions">
                            @if ($conversations->isNotEmpty())
                                <button
                                    type="button"
                                    class="ai-history__clear"
                                    wire:click="confirmClearConversations"
                                    title="Hapus semua riwayat"
                                >
                                    <x-filament::icon icon="heroicon-m-trash" />
                                    Hapus semua
                                </button>
                            @endif
                            <button type="button" class="ai-iconbtn" wire:click="closeHistory" aria-label="Tutup riwayat">
                                <x-filament::icon icon="heroicon-m-x-mark" />
                            </button>
                        </div>
                    </div>

                    <x-filament::button
                        type="button"
                        icon="heroicon-m-plus"
                        class="ai-new-chat"
                        wire:click="startNewChat"
                    >
                        Percakapan Baru
                    </x-filament::button>

                    <ul class="ai-conversations">
                        @forelse ($conversations as $conversation)
                            <li class="ai-conversation-row" wire:key="conv-{{ $conversation->id }}">
                                <button
                                    type="button"
                                    class="ai-conversation {{ (int) $conversation->id === (int) $conversationId ? 'is-active' : '' }}"
                                    wire:click="openConversation({{ (int) $conversation->id }})"
                                >
                                    <span class="ai-conversation__title">
                                        {{ $conversation->title ?: 'Percakapan baru' }}
                                    </span>
                                    <span class="ai-conversation__time">
                                        {{ optional($conversation->last_message_at)->diffForHumans() }}
                                    </span>
                                </button>
                                <button
                                    type="button"
                                    class="ai-conversation__delete"
                                    wire:click="confirmDeleteConversation({{ (int) $conversation->id }})"
                                    title="Hapus percakapan"
                                    aria-label="Hapus percakapan"
                                >
                                    <x-filament::icon icon="heroicon-m-trash" />
                                </button>
                            </li>
                        @empty
                            <li class="ai-conversations__empty">Belum ada riwayat percakapan.</li>
                        @endforelse
                    </ul>
                </aside>
        </div>

        {{-- Modal konfirmasi hapus gaya sendiri (bukan pop-up bawaan browser). --}}
        <div class="ai-modal {{ $deleteConfirmScope ? '' : 'is-hidden' }}">
            <div class="ai-modal__backdrop" wire:click="closeDeleteConfirm"></div>
            <div class="ai-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ai-modal-title">
                <span class="ai-modal__icon"><x-filament::icon icon="heroicon-o-trash" /></span>
                <h3 class="ai-modal__title" id="ai-modal-title">
                    {{ $deleteConfirmScope === 'all' ? 'Hapus semua riwayat?' : 'Hapus percakapan ini?' }}
                </h3>
                <p class="ai-modal__text">
                    @if ($deleteConfirmScope === 'all')
                        Seluruh riwayat percakapan beserta pesannya akan dihapus permanen. Percakapan yang masih memiliki aksi menunggu konfirmasi tidak akan dihapus.
                    @else
                        Percakapan ini beserta seluruh pesannya akan dihapus permanen.
                    @endif
                </p>
                <div class="ai-modal__actions">
                    <button type="button" class="ai-modal__btn" wire:click="closeDeleteConfirm">
                        Batal
                    </button>
                    <button
                        type="button"
                        class="ai-modal__btn ai-modal__btn--danger"
                        wire:click="confirmDeleteConfirmed"
                        wire:loading.attr="disabled"
                        wire:target="confirmDeleteConfirmed"
                    >
                        <span wire:loading.remove wire:target="confirmDeleteConfirmed" class="ai-modal__btn-label">
                            <x-filament::icon icon="heroicon-m-trash" />
                            {{ $deleteConfirmScope === 'all' ? 'Hapus semua' : 'Hapus' }}
                        </span>
                        <span wire:loading wire:target="confirmDeleteConfirmed" class="ai-confirm__spinner" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        </div>

        <style>
        [x-cloak] { display: none !important; }

        .ai-alert { display: flex; align-items: center; gap: .6rem; margin-bottom: .6rem; padding: .7rem .95rem; border-radius: .75rem; background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.3); font-size: .85rem; }
        .ai-alert code { padding: .05rem .3rem; border-radius: .3rem; background: rgba(0,0,0,.06); font-size: .8rem; }

        .ai-chat { display: flex; flex: 1; flex-direction: column; min-height: 0; border-radius: 1rem; background: rgba(148,163,184,.06); border: 1px solid rgba(148,163,184,.2); overflow: hidden; }

        .ai-chat__bar { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .6rem .75rem; border-bottom: 1px solid rgba(148,163,184,.2); background: rgba(255,255,255,.55); }
        .dark .ai-chat__bar { background: rgba(255,255,255,.03); }
        .ai-chat__identity { display: flex; align-items: center; gap: .6rem; min-width: 0; }
        .ai-chat__avatar { display: grid; place-items: center; width: 2.25rem; height: 2.25rem; border-radius: .7rem; color: #fff; background: linear-gradient(145deg, #fbbf24, #d97706); box-shadow: 0 6px 16px rgba(217,119,6,.32); }
        .ai-chat__avatar svg { width: 1.25rem; height: 1.25rem; }
        .ai-chat__meta { display: flex; flex-direction: column; line-height: 1.15; min-width: 0; }
        .ai-chat__meta strong { font-size: .92rem; font-weight: 700; }
        .ai-chat__meta small { font-size: .72rem; color: rgb(100 116 139); }
        .ai-chat__bar-actions { display: flex; align-items: center; gap: .35rem; }

        .ai-iconbtn { display: inline-grid; place-items: center; width: 2.25rem; height: 2.25rem; border-radius: .65rem; border: 1px solid rgba(148,163,184,.3); background: transparent; color: inherit; cursor: pointer; transition: background-color .15s ease, transform .15s ease; }
        .ai-iconbtn:hover { background: rgba(148,163,184,.15); }
        .ai-iconbtn:active { transform: scale(.94); }
        .ai-iconbtn svg { width: 1.15rem; height: 1.15rem; }

        .ai-chat__scroll { flex: 1; display: flex; flex-direction: column; gap: .75rem; padding: 1rem; overflow-y: auto; min-height: 0; scroll-behavior: smooth; }
        .ai-empty { display: flex; flex-direction: column; align-items: center; gap: .4rem; text-align: center; color: rgb(148 163 184); padding: 2rem 1rem; margin: auto; }
        .ai-empty__icon { display: grid; place-items: center; width: 3.25rem; height: 3.25rem; border-radius: 1rem; color: rgb(217 119 6); background: rgba(245,158,11,.12); }
        .ai-empty__icon svg { width: 1.6rem; height: 1.6rem; }
        .ai-empty strong { color: rgb(71 85 105); }
        .dark .ai-empty strong { color: rgb(203 213 225); }
        .ai-chat__suggestions { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: center; margin-top: .75rem; max-width: 46rem; }
        .ai-chip { padding: .4rem .7rem; border-radius: 999px; border: 1px solid rgba(148,163,184,.35); background: transparent; color: inherit; font-size: .78rem; cursor: pointer; transition: background-color .15s ease; }
        .ai-chip:hover { background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.45); }

        .ai-bubble { max-width: 82%; padding: .6rem .85rem; border-radius: .9rem; animation: ai-rise .18s ease; }
        .ai-bubble__role { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; opacity: .7; margin-bottom: .15rem; }
        .ai-bubble__content { font-size: .9rem; line-height: 1.5; overflow-wrap: anywhere; }
        .ai-bubble--user { align-self: flex-end; background: rgba(245,158,11,.16); border: 1px solid rgba(245,158,11,.35); }
        .ai-bubble--assistant { align-self: flex-start; background: rgba(59,130,246,.1); border: 1px solid rgba(59,130,246,.25); }
        @keyframes ai-rise { from { opacity: 0; transform: translateY(.35rem); } to { opacity: 1; transform: translateY(0); } }

        .ai-confirm { align-self: flex-start; max-width: 88%; padding: .6rem .7rem; border-radius: .9rem; background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.4); }
        .ai-confirm__head { display: flex; align-items: center; gap: .4rem; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: rgb(217 119 6); }
        .ai-confirm__icon { display: inline-grid; place-items: center; flex: 0 0 auto; }
        .ai-confirm__icon svg { width: .95rem; height: .95rem; }
        .ai-confirm__summary { margin: .35rem 0 .5rem; font-size: .85rem; line-height: 1.4; }
        .ai-confirm__actions { display: flex; gap: .4rem; flex-wrap: wrap; }
        .ai-confirm__btn { display: inline-flex; align-items: center; gap: .3rem; padding: .35rem .65rem; border-radius: 999px; border: 1px solid rgba(148,163,184,.4); background: transparent; color: inherit; font-size: .76rem; font-weight: 600; cursor: pointer; transition: background-color .15s ease, transform .15s ease; }
        .ai-confirm__btn:hover { background: rgba(148,163,184,.15); }
        .ai-confirm__btn:active { transform: scale(.96); }
        .ai-confirm__btn:disabled { opacity: .6; cursor: progress; }
        .ai-confirm__btn--primary { border-color: transparent; color: #fff; background: linear-gradient(145deg, #fbbf24, #d97706); box-shadow: 0 6px 14px rgba(217,119,6,.3); }
        .ai-confirm__btn--primary:hover { background: linear-gradient(145deg, #fcd34d, #d97706); }
        .ai-confirm__btn-label { display: inline-flex; align-items: center; gap: .3rem; }
        .ai-confirm__btn svg { width: .9rem; height: .9rem; }
        .ai-confirm__spinner { width: .9rem; height: .9rem; border: 2px solid rgba(255,255,255,.5); border-top-color: #fff; border-radius: 50%; animation: ai-spin .7s linear infinite; }

        /* Composer: mic di KIRI, input tengah, tombol kirim di KANAN. */
        .ai-composer { display: flex; align-items: flex-end; gap: .5rem; padding: .7rem .75rem; border-top: 1px solid rgba(148,163,184,.2); background: rgba(255,255,255,.45); }
        .dark .ai-composer { background: rgba(255,255,255,.02); }
        .ai-voice-hint { margin: 0 .75rem .6rem; padding: .5rem .7rem; border-radius: .6rem; font-size: .78rem; color: rgb(180 83 9); background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.4); }
        .dark .ai-voice-hint { color: rgb(252 211 77); }

        /* Indikator sedang merekam: tampil tepat di atas composer. */
        .ai-voice { --ai-mic-gain: .7; display: flex; align-items: center; gap: .8rem; margin: .55rem .75rem 0; padding: .6rem .8rem; border-radius: .9rem; border: 1px solid rgba(239,68,68,.4); background: linear-gradient(135deg, rgba(239,68,68,.12), rgba(220,38,38,.06)); box-shadow: 0 10px 26px rgba(220,38,38,.18); animation: ai-voice-in .2s ease; }
        .ai-voice[hidden] { display: none; }
        .ai-voice__wave { display: flex; align-items: center; justify-content: center; gap: 3px; height: 3.4rem; width: 5.5rem; flex: 0 0 auto; }
        /* Batang lebih tinggi & selalu beranimasi (idle). Amplitudo membesar saat
           ada suara via --ai-mic-gain, jadi jelas terlihat ketika mic merekam. */
        .ai-voice__wave span { width: 4px; height: 3rem; border-radius: 999px; background: rgb(239 68 68); transform-origin: center; animation: ai-voice-bar 1.05s ease-in-out infinite; }
        .ai-voice__wave span:nth-child(1) { animation-delay: -.42s; }
        .ai-voice__wave span:nth-child(2) { animation-delay: -.14s; }
        .ai-voice__wave span:nth-child(3) { animation-delay: -.28s; }
        .ai-voice__wave span:nth-child(4) { animation-delay: 0s; }
        .ai-voice__wave span:nth-child(5) { animation-delay: -.35s; }
        .ai-voice__wave span:nth-child(6) { animation-delay: -.07s; }
        .ai-voice__wave span:nth-child(7) { animation-delay: -.21s; }
        .ai-voice__body { display: flex; flex-direction: column; gap: .1rem; min-width: 0; flex: 1; }
        .ai-voice__title { font-size: .82rem; font-weight: 700; color: rgb(220 38 38); }
        .dark .ai-voice__title { color: rgb(252 165 165); }
        .ai-voice__preview { font-size: .8rem; color: rgb(100 116 139); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dark .ai-voice__preview { color: rgb(148 163 184); }
        .ai-voice__stop { display: inline-flex; align-items: center; gap: .3rem; flex: 0 0 auto; padding: .4rem .7rem; border-radius: 999px; border: 0; cursor: pointer; font-size: .78rem; font-weight: 700; color: #fff; background: linear-gradient(145deg, #f87171, #dc2626); box-shadow: 0 6px 16px rgba(220,38,38,.3); }
        .ai-voice__stop:hover { transform: translateY(-1px); }
        .ai-voice__stop svg { width: 1rem; height: 1rem; }
        @keyframes ai-voice-bar {
            0%, 100% { transform: scaleY(calc(var(--ai-mic-gain) * .28)); }
            50% { transform: scaleY(calc(var(--ai-mic-gain) * .80)); }
        }
        @keyframes ai-voice-in { from { opacity: 0; transform: translateY(.4rem); } to { opacity: 1; transform: translateY(0); } }
        @media (prefers-reduced-motion: reduce) { .ai-voice__wave span { animation-duration: 2.4s; } }
        .ai-composer__input { flex: 1; resize: none; max-height: 8rem; min-height: 2.75rem; padding: .7rem .85rem; border-radius: 1.2rem; border: 1px solid rgba(148,163,184,.35); background: transparent; font-size: .9rem; line-height: 1.4; outline: none; color: inherit; }
        .ai-composer__input:focus { border-color: rgba(245,158,11,.6); }
        .ai-mic, .ai-send { display: inline-grid; place-items: center; flex: 0 0 auto; width: 2.75rem; height: 2.75rem; border-radius: 50%; cursor: pointer; transition: background-color .15s ease, transform .15s ease, box-shadow .15s ease; }
        .ai-mic { border: 1px solid rgba(148,163,184,.4); background: transparent; color: inherit; }
        .ai-mic:hover { background: rgba(148,163,184,.16); }
        .ai-mic svg { width: 1.3rem; height: 1.3rem; }
        .ai-mic.is-listening { color: #fff; border-color: transparent; background: linear-gradient(145deg, #f87171, #dc2626); box-shadow: 0 0 0 6px rgba(239,68,68,.18); animation: ai-pulse 1.2s infinite; }
        .ai-send { border: 0; color: #fff; background: linear-gradient(145deg, #fbbf24, #d97706); box-shadow: 0 8px 18px rgba(217,119,6,.32); }
        .ai-send:hover { box-shadow: 0 10px 22px rgba(217,119,6,.42); transform: translateY(-1px); }
        .ai-send:active { transform: scale(.94); }
        .ai-send[disabled], .ai-mic[disabled] { opacity: .5; cursor: not-allowed; }
        .ai-send svg { width: 1.3rem; height: 1.3rem; }
        .ai-send__loading { width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,.5); border-top-color: #fff; border-radius: 50%; animation: ai-spin .7s linear infinite; }
        @keyframes ai-spin { to { transform: rotate(360deg); } }
        @keyframes ai-pulse { 0%,100% { opacity: 1; } 50% { opacity: .6; } }

        .ai-bubble--pending { opacity: .72; }
        .ai-bubble--typing .ai-bubble__content { display: flex; align-items: center; gap: .25rem; color: rgb(148 163 184); }
        .ai-dot { width: .4rem; height: .4rem; border-radius: 999px; background: currentColor; animation: ai-blink 1.2s infinite both; }
        .ai-dot:nth-child(2) { animation-delay: .2s; }
        .ai-dot:nth-child(3) { animation-delay: .4s; }
        .ai-typing-text { margin-left: .35rem; font-size: .8rem; font-style: italic; }
        @keyframes ai-blink { 0%, 80%, 100% { opacity: .25; } 40% { opacity: 1; } }

        /* Riwayat: drawer dari kanan (mobile & desktop). */
        .ai-history { position: fixed; inset: 0; z-index: 60; animation: ai-fade .15s ease; }
        .ai-history.is-hidden { display: none; }
        .ai-history__backdrop { position: absolute; inset: 0; background: rgba(15,23,42,.45); }
        .ai-history__panel { position: absolute; top: 0; right: 0; z-index: 1; display: flex; flex-direction: column; gap: .6rem; width: min(21rem, 88vw); height: 100%; padding: 1rem; background: #fff; border-left: 1px solid rgba(148,163,184,.25); box-shadow: -18px 0 45px rgba(15,23,42,.22); overflow-y: auto; animation: ai-slide-in .2s ease; }
        .dark .ai-history__panel { background: #101a26; border-left-color: rgba(255,255,255,.1); }
        @keyframes ai-fade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes ai-slide-in { from { transform: translateX(1.5rem); opacity: .6; } to { transform: translateX(0); opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .ai-history, .ai-history__panel { animation: none; } }
        .ai-history__head { display: flex; align-items: center; justify-content: space-between; gap: .4rem; }
        .ai-history__head strong { font-size: .95rem; }
        .ai-history__head-actions { display: flex; align-items: center; gap: .35rem; }
        .ai-history__clear { display: inline-flex; align-items: center; gap: .25rem; padding: .3rem .55rem; border-radius: 999px; border: 1px solid rgba(239,68,68,.35); background: transparent; color: rgb(220 38 38); font-size: .72rem; font-weight: 600; cursor: pointer; }
        .ai-history__clear:hover { background: rgba(239,68,68,.1); }
        .ai-history__clear svg { width: .85rem; height: .85rem; }
        .ai-new-chat { width: 100%; justify-content: center; }
        .ai-conversations { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .25rem; }
        .ai-conversations__empty { font-size: .8rem; color: rgb(148 163 184); padding: .25rem; }
        .ai-conversation-row { display: flex; align-items: stretch; gap: .25rem; min-width: 0; }
        .ai-conversation-row .ai-conversation { flex: 1 1 auto; min-width: 0; }
        .ai-conversation__delete { display: inline-grid; place-items: center; flex: 0 0 auto; width: 2rem; border-radius: .6rem; border: 1px solid transparent; background: transparent; color: rgb(148 163 184); cursor: pointer; }
        .ai-conversation__delete:hover { color: rgb(220 38 38); background: rgba(239,68,68,.1); }
        .ai-conversation__delete svg { width: 1rem; height: 1rem; }
        .ai-conversation { display: flex; flex-direction: column; gap: .15rem; width: 100%; min-width: 0; text-align: left; padding: .55rem .65rem; border-radius: .6rem; border: 1px solid transparent; background: transparent; cursor: pointer; }
        .ai-conversation:hover { background: rgba(148,163,184,.14); }
        .ai-conversation.is-active { background: rgba(245,158,11,.14); border-color: rgba(245,158,11,.35); }
        .ai-conversation__title { max-width: 100%; font-size: .82rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ai-conversation__time { font-size: .7rem; color: rgb(148 163 184); }

        /* Modal konfirmasi internal: latar blur, dialog modern. */
        .ai-modal { position: fixed; inset: 0; z-index: 70; display: grid; place-items: center; padding: 1rem; animation: ai-fade .15s ease; }
        .ai-modal.is-hidden { display: none; }
        .ai-modal__backdrop { position: absolute; inset: 0; background: rgba(15,23,42,.5); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
        .ai-modal__dialog { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; gap: .35rem; width: min(26rem, 92vw); padding: 1.4rem 1.3rem 1.2rem; text-align: center; border-radius: 1.1rem; background: #fff; border: 1px solid rgba(148,163,184,.25); box-shadow: 0 28px 60px rgba(15,23,42,.32); animation: ai-modal-in .22s cubic-bezier(.22,1,.36,1); }
        .dark .ai-modal__dialog { background: #101a26; border-color: rgba(255,255,255,.1); }
        .ai-modal__icon { display: grid; place-items: center; width: 3rem; height: 3rem; margin-bottom: .25rem; border-radius: 1rem; color: rgb(220 38 38); background: rgba(239,68,68,.12); }
        .ai-modal__icon svg { width: 1.5rem; height: 1.5rem; }
        .ai-modal__title { margin: 0; font-size: 1.05rem; font-weight: 700; }
        .ai-modal__text { margin: 0; font-size: .85rem; line-height: 1.5; color: rgb(100 116 139); }
        .dark .ai-modal__text { color: rgb(148 163 184); }
        .ai-modal__actions { display: flex; gap: .55rem; margin-top: 1rem; width: 100%; }
        .ai-modal__btn { display: inline-flex; align-items: center; justify-content: center; gap: .3rem; flex: 1; padding: .6rem .9rem; border-radius: .8rem; border: 1px solid rgba(148,163,184,.4); background: transparent; color: inherit; font-size: .85rem; font-weight: 700; cursor: pointer; transition: background-color .15s ease, transform .15s ease; }
        .ai-modal__btn:hover { background: rgba(148,163,184,.15); }
        .ai-modal__btn:active { transform: scale(.98); }
        .ai-modal__btn:disabled { opacity: .6; cursor: progress; }
        .ai-modal__btn--danger { border-color: transparent; color: #fff; background: linear-gradient(145deg, #f87171, #dc2626); box-shadow: 0 8px 18px rgba(220,38,38,.32); }
        .ai-modal__btn--danger:hover { background: linear-gradient(145deg, #fca5a5, #dc2626); }
        .ai-modal__btn-label { display: inline-flex; align-items: center; gap: .3rem; }
        .ai-modal__btn svg { width: 1rem; height: 1rem; }
        @keyframes ai-modal-in { from { opacity: 0; transform: translateY(.6rem) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @media (prefers-reduced-motion: reduce) { .ai-modal, .ai-modal__dialog { animation: none; } }

        /* Halaman ini adalah elemen .fi-page itu sendiri. Rantai wrapper Filament
           dijadikan flex agar tinggi anak-anaknya ikut stretch ke bawah. */
        .ai-page-wrap { display: flex; flex-direction: column; }
        .ai-page-wrap > section { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; gap: 0; padding-block: .75rem; }
        .ai-page-wrap > section > div { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; gap: 0; }
        .ai-page-wrap > section > div > div { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; gap: 0; }
        .ai-page { position: relative; display: flex; flex: 1 1 auto; flex-direction: column; min-height: 0; }
        /* Tinggi halaman dihitung dari JS (ukur topbar + footer + bottom navbar)
           agar tidak ada scroll halaman dan composer selalu terlihat. */
        .ai-page-wrap { height: var(--ai-page-h, calc(100dvh - 9rem)); }
        /* Saat halaman Asisten aktif, ruang samping/bawah .fi-main dirapikan. */
        .fi-main.ai-main { padding-bottom: .5rem !important; padding-left: .5rem !important; padding-right: .5rem !important; }
        /* Sembunyikan footer kredit di halaman chat Asisten AI saja. */
        html.ai-page-active .wm-global-footer { display: none !important; }
        @media (max-width: 1023px) {
            .ai-page-wrap > section { padding-top: .35rem; padding-bottom: 0; }
            .ai-bubble { max-width: 90%; }
        }
        @media (prefers-reduced-motion: reduce) {
            .ai-bubble { animation: none; }
            .ai-chat__scroll { scroll-behavior: auto; }
        }
    </style>
    </div>
</x-filament-panels::page>
