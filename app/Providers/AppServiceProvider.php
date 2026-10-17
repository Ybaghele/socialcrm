<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema; // 1. ADD THIS IMPORT LINE

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // On shared hosting the web root can't be Laravel's public/ folder, so the
        // contents of public/ are moved into public_html and APP_PUBLIC_PATH points
        // there. No-op in local/dev where the variable is unset.
        if ($publicPath = config('app.public_path')) {
            $this->app->usePublicPath($publicPath);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191); // 2. ADD THIS CONFIGURATION LINE

        // `composer dev` / `php artisan dev` runs serve + queue + vite; add the
        // Reverb websocket server too so the live inbox works out of the box.
        // (Registering is a no-op unless the "dev" command is actually run.)
        DevCommands::artisan('reverb:start', 'reverb');
    }
}
