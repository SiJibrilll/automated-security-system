<?php

namespace App\Providers;

use App\Services\Ai\AiManager;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiManager::class, function ($app) {
            return new AiManager($app);
        });

        $this->app->alias(AiManager::class, 'ai.manager');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
