<?php

namespace App\Providers;

use App\Services\AiConfiguration;
use Illuminate\Pagination\Paginator;
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
        app(AiConfiguration::class)->apply();
        Paginator::useBootstrap();
    }
}
