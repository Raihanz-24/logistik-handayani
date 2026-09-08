<x-filament-panels::page>
    @php($records = $this->backupRecords())

    <div class="bm-page">
        <section class="bm-hero">
            <div>
                <span class="bm-hero__eyebrow">Perlindungan data</span>
                <h1>Backup Sistem</h1>
                <p>Backup database lengkap dan file penting disimpan privat di server. Restore tidak tersedia dari halaman ini agar data production tidak tertimpa tanpa pemeriksaan.</p>
            </div>
            <div class="bm-hero__time">
                <span>Waktu saat ini</span>
                <strong>{{ $this->nowWib() }}</strong>
            </div>
        </section>

        <section class="bm-summary">
            <span class="bm-summary__icon"><x-filament::icon icon="heroicon-o-shield-check" /></span>
            <div>
                <strong>{{ $this->scheduleSummary() }}</strong>
                <span>Backup otomatis memerlukan Cron cPanel untuk menjalankan Laravel Scheduler setiap menit.</span>
            </div>
        </section>

        <div class="bm-grid">
            <section class="bm-card bm-card--settings">
                <div class="bm-card__heading">
                    <div>
                        <span>Pengaturan</span>
                        <h2>Jadwal dan isi backup</h2>
                    </div>
                    <x-filament::icon icon="heroicon-o-clock" />
                </div>

                <form wire:submit="saveSettings">
                    {{ $this->form }}

                    <div class="bm-form-actions">
                        <x-filament::button type="submit" icon="heroicon-m-check" wire:loading.attr="disabled" wire:target="saveSettings">
                            <span wire:loading.remove wire:target="saveSettings">Simpan Pengaturan</span>
                            <span wire:loading wire:target="saveSettings">Menyimpan...</span>
                        </x-filament::button>
                    </div>
                </form>
            </section>

            <aside class="bm-card bm-card--manual">
                <div class="bm-card__heading">
                    <div>
                        <span>Backup manual</span>
                        <h2>Buat salinan sekarang</h2>
                    </div>
                    <x-filament::icon icon="heroicon-o-circle-stack" />
                </div>
                <p>Gunakan sebelum pembaruan besar atau saat Anda ingin menyimpan kondisi data terbaru. Proses dapat membutuhkan waktu lebih lama jika koleksi foto banyak.</p>
                <x-filament::button
                    type="button"
                    icon="heroicon-m-arrow-down-tray"
                    wire:click="runNow"
                    wire:loading.attr="disabled"
                    wire:target="runNow"
                >
                    <span wire:loading.remove wire:target="runNow">Backup Sekarang</span>
                    <span wire:loading wire:target="runNow">Membuat Backup...</span>
                </x-filament::button>
                <small>Jangan menutup halaman selama proses manual sedang berjalan.</small>
            </aside>
        </div>

        <section class="bm-card bm-card--cron">
            <div class="bm-card__heading">
                <div>
                    <span>Wajib untuk otomatis</span>
                    <h2>Cron cPanel</h2>
                </div>
                <x-filament::icon icon="heroicon-o-command-line" />
            </div>
            <p>Tambahkan Cron Job dengan interval <b>setiap 1 menit</b>. Sesuaikan lokasi project dan versi PHP sesuai server Anda.</p>
            <code>cd /path/ke/project && php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code>
            <small>Setelah Cron aktif, sistem akan menjalankan backup sesuai jadwal yang Anda simpan di atas.</small>
        </section>

        <section class="bm-card bm-card--history">
            <div class="bm-card__heading">
                <div>
                    <span>Riwayat</span>
                    <h2>Backup tersimpan</h2>
                </div>
                <span class="bm-history-count">{{ $records->total() }} backup</span>
            </div>

            @if ($records->isEmpty())
                <div class="bm-empty">
                    <x-filament::icon icon="heroicon-o-archive-box" />
                    <strong>Belum ada backup</strong>
                    <span>Tekan Backup Sekarang untuk membuat salinan pertama.</span>
                </div>
            @else
                <div class="bm-history-list">
                    @foreach ($records as $record)
                        <article class="bm-history-row">
                            <div class="bm-history-row__main">
                                <span @class([
                                    'bm-status',
                                    'is-success' => $record->status === \App\Models\BackupRecord::STATUS_COMPLETED,
                                    'is-danger' => $record->status === \App\Models\BackupRecord::STATUS_FAILED,
                                    'is-running' => $record->status === \App\Models\BackupRecord::STATUS_RUNNING,
                                ])>
                                    {{ match ($record->status) {
                                        \App\Models\BackupRecord::STATUS_COMPLETED => 'Berhasil',
                                        \App\Models\BackupRecord::STATUS_FAILED => 'Gagal',
                                        default => 'Berjalan',
                                    } }}
                                </span>
                                <div>
                                    <strong>{{ $record->type === \App\Models\BackupRecord::TYPE_MANUAL ? 'Backup Manual' : 'Backup Terjadwal' }}</strong>
                                    <span>{{ $record->created_at->setTimezone('Asia/Jakarta')->translatedFormat('d M Y, H:i') }} WIB · {{ $record->creator?->name ?? 'Sistem' }}</span>
                                </div>
                            </div>
                            <div class="bm-history-row__detail">
                                @if ($record->status === \App\Models\BackupRecord::STATUS_COMPLETED)
                                    <span>Database {{ $this->formatBytes((int) $record->database_size) }}</span>
                                    @if ($record->files_path)
                                        <span>File {{ $this->formatBytes((int) $record->files_size) }}</span>
                                    @endif
                                    <b>Total {{ $this->formatBytes($record->totalSize()) }}</b>
                                @elseif ($record->status === \App\Models\BackupRecord::STATUS_FAILED)
                                    <span class="is-error">{{ $record->error_message ?: 'Proses backup gagal.' }}</span>
                                @else
                                    <span>Backup sedang diproses.</span>
                                @endif
                            </div>
                            @if ($record->status === \App\Models\BackupRecord::STATUS_COMPLETED)
                                <div class="bm-history-row__actions">
                                    <a href="{{ route('backup.download', ['backup' => $record, 'file' => 'database']) }}">
                                        <x-filament::icon icon="heroicon-m-circle-stack" /> Database
                                    </a>
                                    @if ($record->files_path)
                                        <a href="{{ route('backup.download', ['backup' => $record, 'file' => 'files']) }}">
                                            <x-filament::icon icon="heroicon-m-photo" /> File
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="bm-pagination">
                    {{ $records->onEachSide(1)->links() }}
                </div>
            @endif
        </section>
    </div>

    <style>
        .bm-page{display:grid;gap:1rem}.bm-hero,.bm-card,.bm-summary{border:1px solid var(--wd-border);border-radius:1.15rem;background:var(--wd-surface);box-shadow:var(--wd-shadow)}.bm-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.35rem;background:radial-gradient(circle at 96% 0,rgba(245,179,1,.18),transparent 34%),linear-gradient(135deg,var(--wd-surface),var(--wd-surface-soft))}.bm-hero__eyebrow,.bm-card__heading>div>span{color:var(--wd-amber-light);font-size:.65rem;font-weight:850;letter-spacing:.08em;text-transform:uppercase}.bm-hero h1,.bm-card h2{margin:.25rem 0;color:var(--wd-text);font-weight:850}.bm-hero h1{font-size:1.45rem}.bm-hero p,.bm-card p{max-width:48rem;margin:0;color:var(--wd-muted);font-size:.75rem;line-height:1.6}.bm-hero__time{display:grid;flex:0 0 auto;gap:.2rem;min-width:13rem;padding:.8rem 1rem;border:1px solid var(--wd-feature-border);border-radius:.85rem;background:var(--wd-feature-inset)}.bm-hero__time span{color:var(--wd-muted);font-size:.62rem}.bm-hero__time strong{color:var(--wd-text);font-size:.74rem}.bm-summary{display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;border-color:rgba(37,99,235,.2);background:rgba(59,130,246,.06);box-shadow:none}.bm-summary__icon{display:grid;place-items:center;width:2.4rem;height:2.4rem;flex:0 0 auto;border-radius:.7rem;color:#2563eb;background:rgba(59,130,246,.12)}.bm-summary__icon svg{width:1.2rem}.bm-summary div{display:grid;gap:.15rem}.bm-summary strong{color:var(--wd-text);font-size:.73rem}.bm-summary span{color:var(--wd-muted);font-size:.64rem}.bm-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(18rem,.8fr);gap:1rem}.bm-card{padding:1rem}.bm-card__heading{display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem;margin-bottom:.85rem}.bm-card__heading h2{font-size:1rem}.bm-card__heading>svg{width:1.35rem;color:var(--wd-amber-light)}.bm-form-actions{display:flex;justify-content:flex-end;margin-top:.9rem}.bm-card--manual{display:grid;align-content:start;gap:.85rem;background:linear-gradient(145deg,var(--wd-surface),var(--wd-feature-inset))}.bm-card--manual>.bm-card__heading{margin-bottom:0}.bm-card--manual>small,.bm-card--cron>small{color:var(--wd-muted-soft);font-size:.62rem;line-height:1.5}.bm-card--manual .fi-btn{width:100%}.bm-card--cron{display:grid;gap:.65rem}.bm-card--cron .bm-card__heading{margin-bottom:0}.bm-card--cron code{display:block;overflow-x:auto;padding:.75rem;border:1px solid var(--wd-border);border-radius:.7rem;color:var(--wd-text);background:var(--wd-panel-inset);font-size:.68rem;white-space:nowrap}.bm-card--history{padding:0}.bm-card--history>.bm-card__heading{margin:0;padding:1rem;border-bottom:1px solid var(--wd-border)}.bm-history-count{padding:.28rem .48rem;border-radius:999px;color:var(--wd-amber-light);background:var(--wd-feature-inset);font-size:.62rem;font-weight:800}.bm-history-list{display:grid}.bm-history-row{display:grid;grid-template-columns:minmax(13rem,1.2fr) minmax(10rem,1fr) auto;align-items:center;gap:1rem;padding:.85rem 1rem;border-bottom:1px solid var(--wd-row-border)}.bm-history-row__main{display:flex;align-items:center;gap:.6rem;min-width:0}.bm-history-row__main>div{display:grid;gap:.12rem;min-width:0}.bm-history-row__main strong{color:var(--wd-text);font-size:.72rem}.bm-history-row__main span{overflow:hidden;color:var(--wd-muted);font-size:.6rem;text-overflow:ellipsis;white-space:nowrap}.bm-status{flex:0 0 auto;padding:.25rem .42rem;border-radius:999px;font-size:.56rem;font-weight:850}.bm-status.is-success{color:#047857;background:rgba(16,185,129,.12)}.bm-status.is-danger{color:#b91c1c;background:rgba(239,68,68,.12)}.bm-status.is-running{color:#a16207;background:rgba(245,158,11,.14)}.bm-history-row__detail{display:flex;flex-wrap:wrap;gap:.35rem;color:var(--wd-muted);font-size:.61rem}.bm-history-row__detail span,.bm-history-row__detail b{padding:.22rem .35rem;border-radius:.4rem;background:var(--wd-panel-inset)}.bm-history-row__detail b{color:var(--wd-text)}.bm-history-row__detail .is-error{max-width:22rem;overflow:hidden;color:#b91c1c;text-overflow:ellipsis;white-space:nowrap}.bm-history-row__actions{display:flex;gap:.4rem}.bm-history-row__actions a{display:inline-flex;align-items:center;gap:.25rem;min-height:2rem;padding:.35rem .5rem;border:1px solid var(--wd-border);border-radius:.55rem;color:var(--wd-text);background:var(--wd-surface-soft);font-size:.6rem;font-weight:800;text-decoration:none}.bm-history-row__actions a:hover{color:var(--wd-amber-light);border-color:var(--wd-feature-border)}.bm-history-row__actions svg{width:.82rem}.bm-pagination{padding:.8rem 1rem}.bm-empty{display:grid;place-items:center;gap:.35rem;padding:3rem 1rem;color:var(--wd-muted);text-align:center}.bm-empty svg{width:2rem;color:var(--wd-amber-light)}.bm-empty strong{color:var(--wd-text);font-size:.85rem}.bm-empty span{font-size:.66rem}@media(max-width:800px){.bm-hero{align-items:stretch;flex-direction:column}.bm-hero__time{min-width:0}.bm-grid{grid-template-columns:1fr}.bm-history-row{grid-template-columns:1fr;gap:.65rem}.bm-history-row__actions{display:grid;grid-template-columns:1fr 1fr}.bm-history-row__actions a{justify-content:center}.bm-form-actions .fi-btn{width:100%}}@media(max-width:420px){.bm-history-row__actions{grid-template-columns:1fr}.bm-card--cron code{white-space:normal;word-break:break-all}}
    </style>
</x-filament-panels::page>
