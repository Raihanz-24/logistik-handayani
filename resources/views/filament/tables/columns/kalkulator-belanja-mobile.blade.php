@php
    /** @var \App\Models\KalkulatorBelanja $record */
    $record = $record ?? $getRecord();
    $expenses = $record->pengeluaran;
    $receiptCount = $expenses->sum(fn ($expense): int => $expense->jumlahNota());
    $remaining = $record->sisa_uang;
    $standalone = $standalone ?? false;
    $detailUrl = \App\Filament\Resources\KalkulatorBelanjaResource::getUrl('view', ['record' => $record]);
@endphp

@if ($standalone)
    <a href="{{ $detailUrl }}" wire:navigate class="wm-kb-transaction-link">
@endif
<article class="wm-kb-transaction-card" aria-label="Riwayat {{ $record->judul }}">
    <header class="wm-kb-transaction-card__header">
        <span class="wm-kb-transaction-card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 6h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Zm2 8h3" />
            </svg>
        </span>
        <span class="wm-kb-transaction-card__identity">
            <strong>{{ $record->judul }}</strong>
            <time datetime="{{ $record->tanggal->toDateString() }}">{{ $record->tanggal->translatedFormat('d M Y') }}</time>
        </span>
        <span class="wm-kb-transaction-card__amount">
            <small>Total keluar</small>
            <strong>- {{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah($record->total_pengeluaran) }}</strong>
        </span>
    </header>

    <div class="wm-kb-transaction-card__balance">
        <span>
            <small>Uang awal</small>
            <strong>{{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah((int) $record->uang_awal) }}</strong>
        </span>
        <span class="{{ $remaining < 0 ? 'is-negative' : '' }}">
            <small>Sisa saldo</small>
            <strong>{{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah($remaining) }}</strong>
        </span>
    </div>

    <div class="wm-kb-transaction-card__shops">
        @foreach ($expenses->take(3) as $expense)
            <div>
                <span>{{ $expense->namaSupplier() }}</span>
                <strong>- {{ \App\Filament\Resources\KalkulatorBelanjaResource::rupiah((int) $expense->nominal) }}</strong>
            </div>
        @endforeach

        @if ($expenses->count() > 3)
            <p>+{{ $expenses->count() - 3 }} toko lainnya</p>
        @endif
    </div>

    <footer class="wm-kb-transaction-card__footer">
        <span>{{ $expenses->count() }} toko · {{ $receiptCount }} foto nota</span>
        <strong>Lihat detail <span aria-hidden="true">›</span></strong>
    </footer>
</article>
@if ($standalone)
    </a>
@endif
