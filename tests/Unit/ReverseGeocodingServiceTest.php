<?php

namespace Tests\Unit;

use App\Services\ReverseGeocodingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReverseGeocodingServiceTest extends TestCase
{
    public function test_it_resolves_and_caches_an_indonesian_address(): void
    {
        Cache::flush();
        config()->set('foto_barang.reverse_geocoding.enabled', true);
        config()->set('foto_barang.reverse_geocoding.url', 'https://nominatim.example/reverse');
        config()->set('foto_barang.reverse_geocoding.user_agent', 'LogistikHandayaniTest/1.0');

        Http::fake([
            'nominatim.example/*' => Http::response([
                'display_name' => 'Jalan Raya Paiton, Sumberejo, Probolinggo, Jawa Timur, Indonesia',
                'address' => [
                    'road' => 'Jalan Raya Paiton',
                    'village' => 'Sumberejo',
                    'county' => 'Kabupaten Probolinggo',
                    'state' => 'Jawa Timur',
                    'postcode' => '67291',
                    'country' => 'Indonesia',
                ],
            ]),
        ]);

        $service = app(ReverseGeocodingService::class);
        $first = $service->lookup(-7.717710, 113.537297);
        $second = $service->lookup(-7.717709, 113.537296);

        $this->assertTrue($first['resolved']);
        $this->assertSame('Sumberejo, Jawa Timur, Indonesia', $first['name']);
        $this->assertStringStartsWith('Jalan Raya Paiton, Sumberejo', $first['address']);
        $this->assertStringContainsString('Jawa Timur', $first['address']);
        $this->assertStringContainsString('67291', $first['address']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'LogistikHandayaniTest/1.0'));
    }

    public function test_it_returns_coordinate_fallback_when_the_service_is_unavailable(): void
    {
        Cache::flush();
        config()->set('foto_barang.reverse_geocoding.enabled', true);
        config()->set('foto_barang.reverse_geocoding.url', 'https://nominatim.example/reverse');
        Http::fake(['nominatim.example/*' => Http::response([], 503)]);

        $result = app(ReverseGeocodingService::class)->lookup(-7.717710, 113.537297);

        $this->assertFalse($result['resolved']);
        $this->assertStringContainsString('-7.717710, 113.537297', $result['name']);
        $this->assertStringContainsString('Alamat otomatis belum tersedia', $result['address']);
    }
}
