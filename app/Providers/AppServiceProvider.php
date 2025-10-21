<?php

namespace App\Providers;

use App\Models\Produk;
use App\Observers\ProdukObserver;
use Illuminate\Support\ServiceProvider;
use App\Models\User;
use App\Observers\UserObserver;
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
        Produk::observe(ProdukObserver::class);
        User::observe(UserObserver::class);
    }
}
