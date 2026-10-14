<x-filament-panels::page>
    <div class="ais-page">
        <section class="ais-hero">
            <div>
                <span class="ais-hero__eyebrow">Tata kelola asisten</span>
                <h1>Pengaturan Asisten AI</h1>
                <p>Atur persona, ruang lingkup, dan batas token. Semakin spesifik scope Anda, semakin terarah dan hemat token asistannya.</p>
            </div>
        </section>

        <form wire:submit="save">
            {{ $this->form }}

            <div class="ais-actions">
                <x-filament::button type="submit" icon="heroicon-m-check" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Simpan Pengaturan</span>
                    <span wire:loading wire:target="save">Menyimpan…</span>
                </x-filament::button>

                <x-filament::button color="gray" type="button" icon="heroicon-m-signal" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                    <span wire:loading.remove wire:target="testConnection">Uji Koneksi AI</span>
                    <span wire:loading wire:target="testConnection">Menguji…</span>
                </x-filament::button>
            </div>
        </form>
    </div>

    <style>
        .ais-page { display: flex; flex-direction: column; gap: 1rem; }
        .ais-hero { padding: 1.1rem 1.25rem; border-radius: 1rem; background: linear-gradient(135deg, rgba(245, 158, 11, .12), rgba(245, 158, 11, .02)); border: 1px solid rgba(245, 158, 11, .25); }
        .ais-hero__eyebrow { text-transform: uppercase; letter-spacing: .08em; font-size: .7rem; font-weight: 700; color: rgb(217 119 6); }
        .ais-hero h1 { margin: .15rem 0 .25rem; font-size: 1.25rem; font-weight: 700; }
        .ais-hero p { margin: 0; font-size: .85rem; color: rgb(100 116 139); max-width: 70ch; }
        .ais-actions { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: 1rem; }
    </style>
</x-filament-panels::page>
