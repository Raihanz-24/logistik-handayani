<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FooterLayoutTest extends TestCase
{
    public function test_global_footer_follows_the_page_content(): void
    {
        $styles = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/css/filament-dashboard.css',
        );

        $this->assertStringContainsString('.fi-body .fi-main > .fi-page > section', $styles);
        $this->assertStringContainsString('padding-bottom: clamp(1rem, 2vw, 1.5rem);', $styles);
        $this->assertStringContainsString(".fi-body .fi-main {\n    height: auto;\n    min-height: 0;", $styles);
        $this->assertStringNotContainsString(".fi-body .fi-main {\n    min-height: 100vh;", $styles);
    }
}
