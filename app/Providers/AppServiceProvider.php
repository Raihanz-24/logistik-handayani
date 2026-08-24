<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
