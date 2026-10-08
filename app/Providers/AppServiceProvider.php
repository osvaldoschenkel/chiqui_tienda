<?php

namespace App\Providers;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A scoped value is refreshed for each request, including long-running servers.
        // The resolver runs only when a branded view needs it, never during artisan boot.
        $this->app->scoped('store.brand', fn () => StoreSetting::presentation());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer(['layouts.app', 'auth.login', 'settings.edit', 'dashboard', 'orders.show'], function ($view) {
            $view->with('storeBrand', app('store.brand'))
                ->with('brandColors', config('store.colors'));
        });
    }
}
