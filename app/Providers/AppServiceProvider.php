<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use App\Models\Alert;
use App\Services\OpenSearchService;
use App\Services\TelegramService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind services as singletons
        $this->app->singleton(OpenSearchService::class);
        $this->app->singleton(TelegramService::class);
    }

    public function boot(): void
    {
        // Use Bootstrap 5 paginator (closest to our custom CSS)
        Paginator::useBootstrapFive();

        // Share new-alert count to app layout via a 30-second cache.
        // Prevents inline Model::count() from running on every page load.
        View::composer('layouts.app', function ($view) {
            $count = Cache::remember('siem_new_alert_count', 30, fn () =>
                Alert::where('status', 'new')->count()
            );
            $view->with('layoutNewAlertCount', $count);
        });
    }
}
