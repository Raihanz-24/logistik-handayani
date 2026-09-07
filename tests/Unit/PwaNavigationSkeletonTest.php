<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PwaNavigationSkeletonTest extends TestCase
{
    public function test_pwa_navigation_uses_a_safe_visual_skeleton_while_server_data_loads(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = (string) file_get_contents($root.'/app/Providers/Filament/AdminPanelProvider.php');
        $pwaView = (string) file_get_contents($root.'/resources/views/filament/pwa.blade.php');
        $view = (string) file_get_contents($root.'/resources/views/filament/pwa-navigation-skeleton.blade.php');
        $script = (string) file_get_contents($root.'/resources/js/pwa-navigation-skeleton.js');
        $styles = (string) file_get_contents($root.'/resources/css/filament-dashboard.css');
        $serviceWorker = (string) file_get_contents($root.'/public/service-worker.js');

        $this->assertStringContainsString("view('filament.pwa-navigation-skeleton')", $provider);
        $this->assertStringContainsString("@vite('resources/js/pwa-navigation-skeleton.js')", $pwaView);
        $this->assertStringNotContainsString("@auth\n    @vite('resources/js/pwa-navigation-skeleton.js')", $pwaView);
        $this->assertStringNotContainsString('@vite(', $view);
        $this->assertStringNotContainsString('@auth', $view);
        $this->assertStringContainsString('data-pwa-navigation-skeleton', $view);
        $this->assertStringContainsString("document.addEventListener('click', handleNavigationClick, true)", $script);
        $this->assertStringContainsString("document.addEventListener('livewire:navigate'", $script);
        $this->assertStringNotContainsString("document.addEventListener('livewire:navigating'", $script);
        $this->assertStringContainsString("document.addEventListener('livewire:navigated'", $script);
        $this->assertStringContainsString('PWA_NAVIGATION_TIMEOUT = 12000', $script);
        $this->assertStringContainsString('isMobileViewport', $script);
        $this->assertStringContainsString('.wm-navigation-skeleton.is-visible', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);

        // Authenticated HTML and Livewire data must always remain network-backed.
        $this->assertStringContainsString("request.mode === 'navigate'", $serviceWorker);
        $this->assertStringContainsString('fetch(request).catch(() => caches.match(OFFLINE_URL))', $serviceWorker);
        $this->assertStringNotContainsString("'/admin'", $serviceWorker);
        $this->assertStringNotContainsString("'/livewire/update'", $serviceWorker);
    }
}
