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
        $handayaniLocation = $this->handayaniLocation($latitude, $longitude);

        if ($handayaniLocation !== null) {
            return $handayaniLocation;
        }

        if (! (bool) config('foto_barang.reverse_geocoding.enabled', true)) {
            return $fallback;
        }

        $cacheKey = sprintf('foto-maps:reverse-geocode:v3:%.4f:%.4f', $latitude, $longitude);
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
                            'extratags' => 1,
                            'namedetails' => 1,
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
        $extraTags = is_array($payload['extratags'] ?? null) ? $payload['extratags'] : [];
        $displayName = trim((string) ($payload['display_name'] ?? ''));
        $roadName = $this->firstFilled($parts, [
            'road',
            'pedestrian',
            'service',
            'path',
            'footway',
            'cycleway',
            'highway',
            'route',
        ]);

        if ($roadName === '') {
            $roadName = $this->extractRoad($displayName);
        }

        $house = $this->firstFilled($parts, ['house_number', 'house_name']);
        $milestone = $this->firstFilled($parts, ['addr:milestone', 'milestone', 'kilometer', 'km']);

        if ($milestone === '') {
            $milestone = $this->firstFilled($extraTags, ['addr:milestone', 'milestone', 'kilometer', 'km']);
        }

        if ($milestone === '') {
            $milestone = $this->extractMilestone($displayName);
        }

        $road = trim(implode(' ', array_filter([$roadName, $house])));
        $formattedMilestone = $this->formatMilestone($milestone);

        if ($formattedMilestone !== '' && ! str_contains($this->normalize($road), $this->normalize($formattedMilestone))) {
            $road = trim($road.' '.$formattedMilestone);
        }

        $hamletName = $this->firstFilled($parts, ['hamlet', 'croft', 'isolated_dwelling']);

        if ($hamletName === '') {
            $hamletName = $this->extractHamlet($displayName);
        }

        $hamlet = $this->withPrefix($hamletName, 'Dusun');
        $microLocality = $hamlet !== ''
            ? $hamlet
            : $this->firstFilled($parts, ['neighbourhood', 'quarter', 'subdivision', 'residential']);
        $postcode = $this->firstFilled($parts, ['postcode', 'postal_code']);

        if ($postcode === '') {
            $postcode = $this->extractPostcode($displayName, (string) ($parts['country_code'] ?? ''));
        }

        $addressParts = $this->uniqueParts([
            $road,
            $microLocality,
            trim((string) ($parts['village'] ?? '')),
            trim((string) ($parts['suburb'] ?? '')),
            $this->firstFilled($parts, ['city_district', 'district', 'borough']),
            $this->firstFilled($parts, ['town', 'city', 'municipality']),
            trim((string) ($parts['county'] ?? '')),
            trim((string) ($parts['state_district'] ?? '')),
            trim((string) ($parts['state'] ?? '')),
            $postcode,
            trim((string) ($parts['country'] ?? '')),
        ]);
        $address = implode(', ', $addressParts);

        if ($address === '') {
            $address = $displayName;
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
        $nameParts = $this->uniqueParts([
            $locality,
            trim((string) ($parts['state'] ?? '')),
            trim((string) ($parts['country'] ?? 'Indonesia')),
        ]);

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

    /** @param array<int, string> $parts */
    private function uniqueParts(array $parts): array
    {
        $result = [];
        $seen = [];

        foreach ($parts as $part) {
            $part = trim($part);
            $normalized = $this->normalize($part);

            if ($part === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $result[] = $part;
        }

        return $result;
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^\pL\pN]+/u', '', $value));
    }

    private function extractRoad(string $displayName): string
    {
        foreach (array_map('trim', explode(',', $displayName)) as $segment) {
            if (preg_match('/^(?:jalan|jln?\.?|gang|gg\.?|lorong)\s+/iu', $segment) === 1) {
                return $segment;
            }
        }

        return '';
    }

    private function extractHamlet(string $displayName): string
    {
        foreach (array_map('trim', explode(',', $displayName)) as $segment) {
            if (preg_match('/^(?:dusun|dsn\.?)\s+(.+)$/iu', $segment, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    private function extractMilestone(string $displayName): string
    {
        if (preg_match('/\b(?:km|kilometer)\s*\.?\s*(\d+(?:[.,]\d+)?)\b/iu', $displayName, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function formatMilestone(string $milestone): string
    {
        $milestone = trim($milestone);

        if ($milestone === '') {
            return '';
        }

        if (preg_match('/(?:km|kilometer)\s*\.?\s*(\d+(?:[.,]\d+)?)/iu', $milestone, $matches) === 1) {
            return 'KM '.$matches[1];
        }

        return preg_match('/^\d+(?:[.,]\d+)?$/', $milestone) === 1
            ? 'KM '.$milestone
            : $milestone;
    }

    private function extractPostcode(string $displayName, string $countryCode): string
    {
        if (($countryCode === '' || strtolower($countryCode) === 'id')
            && preg_match('/(?:^|,|\s)(\d{5})(?=,|\s|$)/', $displayName, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /** @return array{name: string, address: string, resolved: bool}|null */
    private function handayaniLocation(float $latitude, float $longitude): ?array
    {
        if (! (bool) config('foto_barang.handayani_location.enabled', true)) {
            return null;
        }

        $radius = max(0, (int) config('foto_barang.handayani_location.radius_meters', 100));
        $targetLatitude = (float) config('foto_barang.handayani_location.latitude', -7.717710);
        $targetLongitude = (float) config('foto_barang.handayani_location.longitude', 113.537297);

        if ($radius === 0 || $this->distanceInMeters($latitude, $longitude, $targetLatitude, $targetLongitude) > $radius) {
            return null;
        }

        return [
            'name' => (string) config(
                'foto_barang.handayani_location.name',
                'Kecamatan Paiton, Jawa Timur, Indonesia',
            ),
            'address' => (string) config(
                'foto_barang.handayani_location.address',
                'Jl. Raya Paiton No. KM 137, Dusun Matikan, Sumberejo, Kec. Paiton, Kabupaten Probolinggo, Jawa Timur 67291, Indonesia',
            ),
            'resolved' => true,
        ];
    }

    private function distanceInMeters(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude,
    ): float {
        $earthRadius = 6_371_000;
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($longitudeDelta / 2) ** 2;
        $a = min(1, max(0, $a));

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
