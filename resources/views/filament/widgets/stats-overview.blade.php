<x-filament-widgets::widget>
    <section class="wd-block">
        <header class="wd-block-heading">
            <div>
                <span class="wd-kicker">Ringkasan operasional</span>
                <h2>Kondisi gudang terkini</h2>
                <p>Ikhtisar data utama dan aktivitas pada periode {{ $periodLabel }}.</p>
            </div>
        </header>

        <div class="wd-stats-grid">
            @foreach ($cards as $card)
                <a href="{{ $card['url'] }}" class="wd-stat-card wd-stat-card--{{ $card['tone'] }}">
                    <div class="wd-stat-card__top">
                        <span class="wd-stat-card__icon">
                            <x-filament::icon :icon="$card['icon']" />
                        </span>
                        <span class="wd-stat-card__arrow">
                            <x-filament::icon icon="heroicon-m-arrow-up-right" />
                        </span>
                    </div>
                    <span class="wd-stat-card__label">{{ $card['label'] }}</span>
                    <strong>{{ number_format($card['value']) }}</strong>
                    <p>{{ $card['description'] }}</p>
                </a>
            @endforeach
        </div>
    </section>
</x-filament-widgets::widget>
