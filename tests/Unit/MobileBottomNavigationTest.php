<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MobileBottomNavigationTest extends TestCase
{
    public function test_mobile_navigation_is_registered_and_contains_safe_quick_links(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = (string) file_get_contents($root.'/app/Providers/Filament/AdminPanelProvider.php');
        $view = (string) file_get_contents($root.'/resources/views/filament/mobile-bottom-navigation.blade.php');
        $styles = (string) file_get_contents($root.'/resources/css/filament-dashboard.css');
        $swipeNavigation = (string) file_get_contents($root.'/resources/js/mobile-swipe-navigation.js');

        $this->assertStringContainsString('PanelsRenderHook::BODY_END', $provider);
        $this->assertStringContainsString("view('filament.mobile-bottom-navigation')", $provider);
        $this->assertStringContainsString('@auth', $view);
        $this->assertStringContainsString('$store.sidebar.open()', $view);
        $this->assertStringContainsString('BarangLokasiResource::getUrl', $view);
        $this->assertStringContainsString('FotoBarangMaps::getUrl', $view);
        $this->assertStringContainsString('MutasiResource::getUrl', $view);
        $this->assertStringContainsString('Dashboard::getUrl', $view);
        $this->assertStringContainsString('::canViewAny()', $view);
        $this->assertStringContainsString('::canAccess()', $view);
        $this->assertStringContainsString("document.addEventListener('livewire:navigated'", $swipeNavigation);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $styles);
        $this->assertStringContainsString('@media (max-width: 1023px)', $styles);
        $this->assertStringContainsString('grid-template-columns:repeat(5', $styles);
        $this->assertStringContainsString('wire:navigate.hover', $view);
        $this->assertStringContainsString('mobileSwipeNavigation(@js($swipeNavigationPages))', $view);
        $this->assertStringContainsString('wm-swipe-cue', $view);
        $this->assertStringContainsString('event.preventDefault()', $swipeNavigation);
        $this->assertStringContainsString('style.overflowX', $swipeNavigation);
        $this->assertStringContainsString("'.fi-modal'", $swipeNavigation);
        $this->assertStringContainsString("'.fm-live-camera'", $swipeNavigation);
        $this->assertStringContainsString('Math.abs(deltaX) >= 72', $swipeNavigation);
        $this->assertStringContainsString("new MouseEvent('mouseenter'", $swipeNavigation);
        $this->assertStringContainsString('this.pagePath(page) === current', $swipeNavigation);
        $this->assertStringContainsString('updatePageDrag(deltaX', $swipeNavigation);
        $this->assertStringContainsString('returnPageVisual()', $swipeNavigation);
        $this->assertStringContainsString('commitPageVisual(direction)', $swipeNavigation);
        $this->assertStringContainsString('animateIncomingPage(direction)', $swipeNavigation);
        $this->assertStringContainsString('wm-page-swipe-dragging', $styles);
        $this->assertStringContainsString('wm-page-enter-next', $styles);
        $this->assertStringContainsString('wm-page-enter-previous', $styles);
        $this->assertStringContainsString('window.setTimeout(() => {', $swipeNavigation);
        $this->assertStringContainsString('}, 12000)', $swipeNavigation);
    }
}
