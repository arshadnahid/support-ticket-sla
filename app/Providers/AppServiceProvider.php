<?php

namespace App\Providers;

use App\Services\Ticket\Assignment\AssignmentStrategy;
use App\Services\Ticket\Assignment\LeastWorkloadStrategy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The rule that picks an agent for automatic assignment. Swap the class to change it.
        $this->app->bind(AssignmentStrategy::class, LeastWorkloadStrategy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Brute-force protection for login/register/refresh: per email+IP and per IP.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
    }
}
