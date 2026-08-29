<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use App\Services\AuditLogger;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as IlluminateView;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table
                ->paginationPageOptions([10, 25, 50, 100])
                ->defaultPaginationPageOption(10)
                ->extremePaginationLinks();
        });

        View::composer('filament::components.pagination.*', function (IlluminateView $view): void {
            $paginator = $view->getData()['paginator'] ?? null;

            if (is_object($paginator) && method_exists($paginator, 'onEachSide')) {
                $paginator->onEachSide(2);
            }
        });

        User::observe(UserObserver::class);

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                app(AuditLogger::class)->authentication($event->user, 'login');
            }
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User) {
                app(AuditLogger::class)->authentication($event->user, 'logout');
            }
        });
    }
}
