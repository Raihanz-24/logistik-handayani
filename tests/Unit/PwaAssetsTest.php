<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PwaAssetsTest extends TestCase
{
    public function test_manifest_defines_an_installable_standalone_application(): void
    {
        $manifestPath = dirname(__DIR__, 2).'/public/manifest.webmanifest';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/admin', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#102031', $manifest['theme_color']);
        $this->assertContains('192x192', array_column($manifest['icons'], 'sizes'));
        $this->assertContains('512x512', array_column($manifest['icons'], 'sizes'));
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
    }

    public function test_service_worker_keeps_admin_pages_out_of_the_asset_cache(): void
    {
        $serviceWorker = (string) file_get_contents(dirname(__DIR__, 2).'/public/service-worker.js');

        $this->assertStringContainsString("request.mode === 'navigate'", $serviceWorker);
        $this->assertStringContainsString("fetch(request).catch(() => caches.match(OFFLINE_URL))", $serviceWorker);
        $this->assertStringNotContainsString("'/admin'", $serviceWorker);
        $this->assertStringNotContainsString("'/media/'", $serviceWorker);
    }

    public function test_login_contains_the_pwa_install_action(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $loginView = (string) file_get_contents($projectRoot.'/resources/views/filament/pages/auth/login.blade.php');
        $pwaView = (string) file_get_contents($projectRoot.'/resources/views/filament/pwa.blade.php');

        $this->assertStringContainsString('data-pwa-install', $loginView);
        $this->assertStringContainsString('Unduh Aplikasi', $loginView);
        $this->assertStringContainsString("window.addEventListener('beforeinstallprompt'", $pwaView);
        $this->assertStringContainsString("window.addEventListener('appinstalled'", $pwaView);
    }
}
