<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DashboardMobileLayoutTest extends TestCase
{
    public function test_mobile_stock_chart_and_restock_ranking_do_not_require_horizontal_scrolling(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $styles = (string) file_get_contents($projectRoot.'/resources/css/filament-dashboard.css');
        $restock = (string) file_get_contents(
            $projectRoot.'/resources/views/filament/widgets/restock-recommendation.blade.php',
        );

        $this->assertStringContainsString('grid-template-areas:', $styles);
        $this->assertStringContainsString('.wd-chart-row {', $styles);
        $this->assertStringContainsString('.wd-rank-row {', $styles);
        $this->assertStringContainsString('min-width: 0;', $styles);
        $this->assertStringContainsString('wd-rank-row__metrics', $restock);
        $this->assertStringContainsString("number_format(\$item['nilai_preferensi'], 4)", $restock);
    }

    public function test_dashboard_hero_shows_wib_and_paiton_weather_without_stock_condition(): void
    {
        $hero = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/widgets/dashboard-hero.blade.php',
        );

        $this->assertStringContainsString('{{ $currentTime }} WIB', $hero);
        $this->assertStringContainsString('Cuaca hari ini', $hero);
        $this->assertStringNotContainsString('Kondisi stok', $hero);
    }
}
