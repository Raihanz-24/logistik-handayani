<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget as BaseAccountWidget;

class AccountWidget extends BaseAccountWidget
{
    protected static ?int $sort = -999;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';
}
