<?php

namespace Filament\Widgets;

class AccountWidget extends Widget
{
    protected static ?int $sort = -999;
    protected static bool $isLazy = false;
    protected int|string|array $columnSpan = 'full';

    /**
     * @var view-string
     */
    protected static string $view = 'filament-panels::widgets.account-widget';
}
