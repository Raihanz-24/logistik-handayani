<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PaitonWeatherService
{
    /** @return array<string, mixed> */
    public function current(): array
    {
        $cacheKey = 'dashboard.weather.paiton.current.v1';
        $lastKnownKey = 'dashboard.weather.paiton.last-known.v1';

        if (Cache::has($cacheKey)) {
            return (array) Cache::get($cacheKey);
        }

        try {
            $weather = $this->fetch();

            Cache::put(
                $cacheKey,
                $weather,
                now()->addMinutes((int) config('dashboard.weather.cache_minutes', 20)),
            );
            Cache::put($lastKnownKey, $weather, now()->addHours(6));

            return $weather;
        } catch (Throwable) {
            $lastKnown = Cache::get($lastKnownKey);
            $weather = is_array($lastKnown)
                ? [...$lastKnown, 'stale' => true]
                : $this->fallback();

            Cache::put($cacheKey, $weather, now()->addMinutes(5));

            return $weather;
        }
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $response = Http::acceptJson()
            ->connectTimeout(2)
            ->timeout(4)
            ->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => config('dashboard.weather.latitude'),
                'longitude' => config('dashboard.weather.longitude'),
                'current' => implode(',', [
                    'temperature_2m',
                    'apparent_temperature',
                    'relative_humidity_2m',
                    'weather_code',
                    'wind_speed_10m',
                    'is_day',
                ]),
                'daily' => implode(',', [
                    'temperature_2m_max',
                    'temperature_2m_min',
                    'precipitation_probability_max',
                ]),
                'timezone' => config('dashboard.weather.timezone'),
                'forecast_days' => 1,
            ])
            ->throw()
            ->json();

        $current = (array) ($response['current'] ?? []);
        $daily = (array) ($response['daily'] ?? []);
        $code = (int) ($current['weather_code'] ?? -1);
        $condition = $this->condition($code, (bool) ($current['is_day'] ?? true));

        if (! isset($current['temperature_2m'])) {
            throw new \RuntimeException('Data suhu cuaca tidak tersedia.');
        }

        return [
            'available' => true,
            'stale' => false,
            'location' => (string) config('dashboard.weather.location', 'Paiton'),
            'description' => $condition['description'],
            'icon' => $condition['icon'],
            'temperature' => (float) $current['temperature_2m'],
            'apparent_temperature' => (float) ($current['apparent_temperature'] ?? $current['temperature_2m']),
            'humidity' => (int) ($current['relative_humidity_2m'] ?? 0),
            'wind_speed' => (float) ($current['wind_speed_10m'] ?? 0),
            'temperature_max' => (float) ($daily['temperature_2m_max'][0] ?? $current['temperature_2m']),
            'temperature_min' => (float) ($daily['temperature_2m_min'][0] ?? $current['temperature_2m']),
            'rain_probability' => (int) ($daily['precipitation_probability_max'][0] ?? 0),
        ];
    }

    /** @return array{description: string, icon: string} */
    private function condition(int $code, bool $isDay): array
    {
        return match (true) {
            $code === 0 => [
                'description' => $isDay ? 'Cerah' : 'Cerah berawan',
                'icon' => $isDay ? 'heroicon-o-sun' : 'heroicon-o-moon',
            ],
            in_array($code, [1, 2], true) => ['description' => 'Cerah berawan', 'icon' => 'heroicon-o-cloud'],
            $code === 3 => ['description' => 'Berawan', 'icon' => 'heroicon-o-cloud'],
            in_array($code, [45, 48], true) => ['description' => 'Berkabut', 'icon' => 'heroicon-o-bars-3-bottom-left'],
            in_array($code, [51, 53, 55, 56, 57], true) => ['description' => 'Gerimis', 'icon' => 'heroicon-o-cloud-arrow-down'],
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => ['description' => 'Hujan', 'icon' => 'heroicon-o-cloud-arrow-down'],
            in_array($code, [95, 96, 99], true) => ['description' => 'Hujan petir', 'icon' => 'heroicon-o-bolt'],
            default => ['description' => 'Berawan', 'icon' => 'heroicon-o-cloud'],
        };
    }

    /** @return array<string, mixed> */
    private function fallback(): array
    {
        return [
            'available' => false,
            'stale' => false,
            'location' => (string) config('dashboard.weather.location', 'Paiton'),
            'description' => 'Cuaca belum tersedia',
            'icon' => 'heroicon-o-cloud',
            'temperature' => null,
            'apparent_temperature' => null,
            'humidity' => null,
            'wind_speed' => null,
            'temperature_max' => null,
            'temperature_min' => null,
            'rain_probability' => null,
        ];
    }
}
