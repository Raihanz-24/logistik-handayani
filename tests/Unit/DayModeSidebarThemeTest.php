<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DayModeSidebarThemeTest extends TestCase
{
    public function test_day_mode_sidebar_uses_navy_and_gold_without_changing_dark_mode(): void
    {
        $styles = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/css/filament-dashboard.css',
        );

        $this->assertStringContainsString('html:not(.dark) .fi-body .fi-sidebar {', $styles);
        $this->assertStringContainsString('linear-gradient(180deg, #102a46 0%, #0b1e34 58%, #081829 100%)', $styles);
        $this->assertStringContainsString('.fi-sidebar-item-active .fi-sidebar-item-button {', $styles);
        $this->assertStringContainsString('linear-gradient(135deg, #f8d568 0%, #e3aa24 100%)', $styles);
        $this->assertStringContainsString('scrollbar-color: #dca51c', $styles);
        $this->assertStringContainsString('.fi-sidebar-nav::-webkit-scrollbar-thumb', $styles);
    }
}
