<?php

namespace App\Filament\Widgets;

use App\Services\PaitonWeatherService;
use Filament\Widgets\Widget;

class DashboardHero extends Widget
{
    protected static string $view = 'filament.widgets.dashboard-hero';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $userName = trim((string) auth()->user()?->name);
        $firstName = str($userName)->before(' ')->toString() ?: 'Admin';
        $now = now('Asia/Jakarta');
        $hour = (int) $now->format('H');

        $greeting = match (true) {
            $hour < 11 => 'Selamat pagi',
            $hour < 15 => 'Selamat siang',
            $hour < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };

        return [
            'firstName' => $firstName,
            'greeting' => $greeting,
            'currentDate' => $now->translatedFormat('l, d F Y'),
            'currentTime' => $now->format('H:i'),
            'weather' => app(PaitonWeatherService::class)->current(),
        ];
    }
}
