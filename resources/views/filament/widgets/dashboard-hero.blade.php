<x-filament-widgets::widget>
    <section class="wd-hero">
        <div class="wd-hero__orb wd-hero__orb--one"></div>
        <div class="wd-hero__orb wd-hero__orb--two"></div>

        <div class="wd-hero__content">
            <span class="wd-eyebrow">
                <x-filament::icon icon="heroicon-m-cube-transparent" />
                Logistik Taman Air Handayani
            </span>

            <h1>{{ $greeting }}, <strong>{{ $firstName }}</strong></h1>
            <p>
                Kelola kebutuhan logistik hari ini dengan informasi yang ringkas,
                jelas, dan mudah dipantau.
            </p>
        </div>

        <div class="wd-hero__metrics">
            <article class="wd-hero-metric">
                <span class="wd-hero-metric__icon">
                    <x-filament::icon icon="heroicon-o-clock" />
                </span>
                <div>
                    <small>Waktu saat ini</small>
                    <strong>{{ $currentTime }} WIB</strong>
                    <span>{{ $currentDate }}</span>
                </div>
            </article>

            <article class="wd-hero-metric wd-weather-card">
                <span class="wd-hero-metric__icon wd-hero-metric__icon--weather">
                    <x-filament::icon :icon="$weather['icon']" />
                </span>
                <div>
                    <small>Cuaca hari ini · {{ $weather['location'] }}</small>
                    @if ($weather['available'])
                        <strong>{{ number_format($weather['temperature'], 0, ',', '.') }}°C · {{ $weather['description'] }}</strong>
                        <span>
                            Min {{ number_format($weather['temperature_min'], 0, ',', '.') }}° ·
                            Maks {{ number_format($weather['temperature_max'], 0, ',', '.') }}° ·
                            Hujan {{ $weather['rain_probability'] }}%
                            @if ($weather['stale']) · data terakhir @endif
                        </span>
                    @else
                        <strong>{{ $weather['description'] }}</strong>
                        <span>Informasi waktu tetap dapat digunakan.</span>
                    @endif
                </div>
            </article>
        </div>
    </section>
</x-filament-widgets::widget>
