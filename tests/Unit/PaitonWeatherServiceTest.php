<?php

namespace Tests\Unit;

use App\Services\PaitonWeatherService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaitonWeatherServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_it_returns_and_caches_current_paiton_weather(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response([
                'current' => [
                    'temperature_2m' => 30.4,
                    'apparent_temperature' => 34.1,
                    'relative_humidity_2m' => 72,
                    'weather_code' => 61,
                    'wind_speed_10m' => 8.5,
                    'is_day' => 1,
                ],
                'daily' => [
                    'temperature_2m_max' => [32.0],
                    'temperature_2m_min' => [25.0],
                    'precipitation_probability_max' => [70],
                ],
            ]),
        ]);

        $service = app(PaitonWeatherService::class);
        $weather = $service->current();
        $service->current();

        $this->assertTrue($weather['available']);
        $this->assertSame('Hujan', $weather['description']);
        $this->assertSame(30.4, $weather['temperature']);
        $this->assertSame(70, $weather['rain_probability']);
        Http::assertSentCount(1);
    }

    public function test_dashboard_remains_available_when_weather_api_fails(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response([], 500)]);

        $weather = app(PaitonWeatherService::class)->current();

        $this->assertFalse($weather['available']);
        $this->assertSame('Cuaca belum tersedia', $weather['description']);
    }
}
