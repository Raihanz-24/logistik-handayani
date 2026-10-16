<x-filament-panels::page>
    @php
        $status = $this->status();
        $primary = $status['primary'];
        $fallback = $status['fallback'];
    @endphp

    <div class="aips-page">
        <section class="aips-hero">
            <div>
                <span class="aips-hero__eyebrow">Infrastruktur AI</span>
                <h1>Status Provider AI</h1>
                <p>Menentukan model mana yang melayani Asisten: <strong>Xcode AI lokal</strong> (mesin sendiri) atau <strong>cloud</strong> (API key cadangan). Ubah lewat <code>AI_DRIVER</code> di <code>.env</code>.</p>
            </div>
        </section>

        @unless ($status['enabled'])
            <div class="aips-warning">
                Asisten AI sedang <strong>nonaktif</strong> (<code>AI_ENABLED=false</code>). Status di bawah hanya informasi konfigurasi.
            </div>
        @endunless

        <section class="aips-cards">
            <div class="aips-card">
                <span class="aips-card__label">Driver aktif</span>
                <span class="aips-card__value">{{ strtoupper($status['driver']) }}</span>
                <span class="aips-card__hint">cloud = API key · local = Xcode AI sendiri · auto = lokal dulu → cloud</span>
            </div>
            <div class="aips-card">
                <span class="aips-card__label">Provider utama</span>
                <span class="aips-card__value">{{ $primary['label'] }}</span>
                <span class="aips-card__hint">{{ $primary['model'] ?: '—' }}</span>
            </div>
            <div class="aips-card">
                <span class="aips-card__label">Cadangan</span>
                <span class="aips-card__value">{{ $fallback['label'] ?? 'Tidak ada' }}</span>
                <span class="aips-card__hint">{{ $fallback['model'] ?? '—' }}</span>
            </div>
        </section>

        {{-- Profil lokal --}}
        <section class="aips-section">
            <div class="aips-section__head">
                <h2 class="aips-section__title">Xcode AI (Lokal)</h2>
                <span class="aips-badge {{ $this->ready('local') ? 'aips-badge--ok' : 'aips-badge--warn' }}">
                    {{ $this->ready('local') ? 'siap' : 'belum lengkap' }}
                </span>
            </div>
            <dl class="aips-dl">
                <dt>Base URL</dt><dd>{{ config('ai.profiles.local.base_url') }}</dd>
                <dt>Model</dt><dd>{{ config('ai.profiles.local.model') }}</dd>
                <dt>API key</dt><dd>{{ config('ai.profiles.local.api_key') === '' ? '—' : 'terisi (lokal)' }}</dd>
            </dl>
            <x-filament::button color="gray" icon="heroicon-m-signal" wire:click="testLocal" wire:loading.attr="disabled" wire:target="testLocal">
                <span wire:loading.remove wire:target="testLocal">Uji Koneksi Lokal</span>
                <span wire:loading wire:target="testLocal">Menguji…</span>
            </x-filament::button>
        </section>

        {{-- Profil cloud --}}
        <section class="aips-section">
            <div class="aips-section__head">
                <h2 class="aips-section__title">Cloud (API key cadangan)</h2>
                <span class="aips-badge {{ $this->ready('cloud') ? 'aips-badge--ok' : 'aips-badge--warn' }}">
                    {{ $this->ready('cloud') ? 'siap' : 'belum lengkap' }}
                </span>
            </div>
            <dl class="aips-dl">
                <dt>Base URL</dt><dd>{{ config('ai.base_url') }}</dd>
                <dt>Model</dt><dd>{{ config('ai.model') }}</dd>
                <dt>API key</dt><dd>{{ filled(config('ai.api_key')) ? 'terisi' : 'kosong' }}</dd>
            </dl>
            <x-filament::button color="gray" icon="heroicon-m-signal" wire:click="testCloud" wire:loading.attr="disabled" wire:target="testCloud">
                <span wire:loading.remove wire:target="testCloud">Uji Koneksi Cloud</span>
                <span wire:loading wire:target="testCloud">Menguji…</span>
            </x-filament::button>
        </section>

        {{-- Panduan --}}
        <section class="aips-section">
            <h2 class="aips-section__title">Cara berpindah mode</h2>
            <ul class="aips-list">
                <li><code>AI_DRIVER=cloud</code> — perilaku lama: hanya API key cloud.</li>
                <li><code>AI_DRIVER=local</code> — Xcode AI lokal saja, tanpa cloud.</li>
                <li><code>AI_DRIVER=auto</code> — coba lokal dulu; bila gagal, otomatis pindah ke cloud (API key cadangan).</li>
            </ul>
            <p class="aips-muted">Contoh server model lokal OpenAI-compatible: Ollama (<code>http://127.0.0.1:11434/v1</code>), vLLM, LM Studio, llama.cpp. Model wajib mendukung <em>tool-calling</em>.</p>
        </section>
    </div>

    <style>
        .aips-page { display: flex; flex-direction: column; gap: 1rem; }
        .aips-hero { padding: 1.1rem 1.25rem; border-radius: 1rem; background: linear-gradient(135deg, rgba(139, 92, 246, .12), rgba(139, 92, 246, .02)); border: 1px solid rgba(139, 92, 246, .25); }
        .aips-hero__eyebrow { text-transform: uppercase; letter-spacing: .08em; font-size: .7rem; font-weight: 700; color: rgb(124 58 237); }
        .aips-hero h1 { margin: .15rem 0 .25rem; font-size: 1.25rem; font-weight: 700; }
        .aips-hero p { margin: 0; font-size: .85rem; color: rgb(100 116 139); max-width: 80ch; }
        .aips-warning { padding: .8rem 1rem; border-radius: .8rem; background: rgba(245, 158, 11, .12); border: 1px solid rgba(245, 158, 11, .3); font-size: .85rem; }
        .aips-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .75rem; }
        .aips-card { display: flex; flex-direction: column; gap: .15rem; padding: 1rem 1.1rem; border-radius: .9rem; border: 1px solid rgba(148, 163, 184, .3); background: rgba(148, 163, 184, .06); }
        .aips-card__label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: rgb(100 116 139); font-weight: 600; }
        .aips-card__value { font-size: 1.3rem; font-weight: 800; line-height: 1.2; }
        .aips-card__hint { font-size: .78rem; color: rgb(100 116 139); }
        .aips-section { padding: 1rem 1.1rem; border-radius: .9rem; border: 1px solid rgba(148, 163, 184, .25); background: rgba(148, 163, 184, .04); }
        .aips-section__head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .5rem; }
        .aips-section__title { margin: 0; font-size: 1rem; font-weight: 700; }
        .aips-dl { display: grid; grid-template-columns: 120px 1fr; gap: .25rem .8rem; margin: 0 0 .8rem; font-size: .82rem; }
        .aips-dl dt { color: rgb(100 116 139); font-weight: 600; }
        .aips-dl dd { margin: 0; word-break: break-all; }
        .aips-badge { font-size: .7rem; font-weight: 700; padding: .12rem .5rem; border-radius: 999px; }
        .aips-badge--ok { background: rgba(34, 197, 94, .15); color: rgb(21 128 61); }
        .aips-badge--warn { background: rgba(245, 158, 11, .18); color: rgb(180 83 9); }
        .aips-list { margin: 0 0 .5rem; padding-left: 1.1rem; font-size: .84rem; line-height: 1.6; }
        .aips-muted { font-size: .82rem; color: rgb(100 116 139); margin: 0; }
    </style>
</x-filament-panels::page>
