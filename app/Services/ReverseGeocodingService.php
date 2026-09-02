<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ReverseGeocodingService
{
    /** @return array{name: string, address: string, resolved: bool} */
    public function lookup(float $latitude, float $longitude): array
    {
        $fallback = $this->fallback($latitude, $longitude);

        if (! (bool) config('foto_barang.reverse_geocoding.enabled', true)) {
            return $fallback;
        }

        $cacheKey = sprintf('foto-maps:reverse-geocode:v2:%.4f:%.4f', $latitude, $longitude);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['name'], $cached['address'], $cached['resolved'])) {
            return $cached;
        }

        try {
            $result = Cache::lock('foto-maps:reverse-geocode:request', 10)->block(6, function () use ($latitude, $longitude): array {
                $lastRequestAt = (float) Cache::get('foto-maps:reverse-geocode:last-request', 0);
                $remainingMicroseconds = (int) round(max(0, 1.05 - (microtime(true) - $lastRequestAt)) * 1_000_000);

                if ($remainingMicroseconds > 0) {
                    usleep($remainingMicroseconds);
                }

                try {
                    $response = Http::acceptJson()
                        ->withHeaders(['User-Agent' => $this->userAgent()])
                        ->timeout((int) config('foto_barang.reverse_geocoding.timeout', 5))
                        ->get((string) config('foto_barang.reverse_geocoding.url'), [
                            'format' => 'jsonv2',
                            'lat' => $latitude,
                            'lon' => $longitude,
                            'zoom' => 18,
                            'addressdetails' => 1,
                            'layer' => 'address',
                            'accept-language' => 'id',
                        ]);
                } finally {
                    Cache::put('foto-maps:reverse-geocode:last-request', microtime(true), now()->addMinute());
                }

                $response->throw();

                return $this->formatResult((array) $response->json(), $latitude, $longitude);
            });
        } catch (Throwable $exception) {
            report($exception);
            $result = $fallback;
        }

        Cache::put(
            $cacheKey,
            $result,
            ($result['resolved'] ?? false)
                ? now()->addDays((int) config('foto_barang.reverse_geocoding.cache_days', 30))
                : now()->addMinutes(5),
        );

        return $result;
    }

    /** @return array{name: string, address: string, resolved: bool} */
    private function formatResult(array $payload, float $latitude, float $longitude): array
    {
        $parts = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $road = trim(implode(' ', array_filter([
            $this->firstFilled($parts, ['road', 'pedestrian', 'residential', 'service', 'path', 'footway']),
            (string) ($parts['house_number'] ?? ''),
        ])));
        $hamlet = $this->withPrefix(trim((string) ($parts['hamlet'] ?? '')), 'Dusun');
        $microLocality = $hamlet !== ''
            ? $hamlet
            : $this->firstFilled($parts, ['neighbourhood', 'quarter']);
        $addressParts = array_values(array_unique(array_filter([
            $road,
            $microLocality,
            trim((string) ($parts['village'] ?? '')),
            trim((string) ($parts['suburb'] ?? '')),
            $this->firstFilled($parts, ['city_district', 'district']),
            $this->firstFilled($parts, ['town', 'city', 'municipality']),
            trim((string) ($parts['county'] ?? '')),
            trim((string) ($parts['state'] ?? '')),
            trim((string) ($parts['postcode'] ?? '')),
            trim((string) ($parts['country'] ?? '')),
        ])));
        $address = implode(', ', $addressParts);

        if ($address === '') {
            $address = trim((string) ($payload['display_name'] ?? ''));
        }

        if ($address === '') {
            return $this->fallback($latitude, $longitude);
        }

        $locality = $this->firstFilled($parts, [
            'village',
            'suburb',
            'quarter',
            'neighbourhood',
            'hamlet',
            'city_district',
            'town',
            'city',
            'municipality',
            'county',
        ]);
        $nameParts = array_values(array_unique(array_filter([
            $locality,
            trim((string) ($parts['state'] ?? '')),
            trim((string) ($parts['country'] ?? 'Indonesia')),
        ])));

        return [
            'name' => implode(', ', $nameParts) ?: 'Lokasi GPS',
            'address' => $address,
            'resolved' => true,
        ];
    }

    private function firstFilled(array $parts, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($parts[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function withPrefix(string $value, string $prefix): string
    {
        if ($value === '' || str_starts_with(strtolower($value), strtolower($prefix).' ')) {
            return $value;
        }

        return $prefix.' '.$value;
    }

    /** @return array{name: string, address: string, resolved: bool} */
    private function fallback(float $latitude, float $longitude): array
    {
        $coordinates = sprintf('%.6f, %.6f', $latitude, $longitude);

        return [
            'name' => 'Lokasi GPS '.$coordinates,
            'address' => 'Koordinat '.$coordinates.' · Alamat otomatis belum tersedia',
            'resolved' => false,
        ];
    }

    private function userAgent(): string
    {
        $configured = trim((string) config('foto_barang.reverse_geocoding.user_agent'));

        if ($configured !== '') {
            return $configured;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'handayani.my.id';

        return "LogistikHandayani/1.0 ({$host})";
    }
}
