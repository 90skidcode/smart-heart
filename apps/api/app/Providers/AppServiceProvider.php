<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Named limiters, each with its own counter. (Plain "throttle:x,y" shares one counter per
        // user/IP across every route that uses it, and hospital PCs share one public IP.)
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(10)->by('login|'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(60)->by('login-ip|'.$r->ip()),
        ]);
        RateLimiter::for('self-entry', fn (Request $r) => Limit::perMinute(120)->by('se|'.$r->route('token').'|'.$r->ip()));
        RateLimiter::for('sign', fn (Request $r) => Limit::perMinute(10)->by('sign|'.($r->user()?->id ?? $r->ip())));
        RateLimiter::for('app-activate', fn (Request $r) => Limit::perMinute(10)->by('app-act|'.$r->ip()));
        RateLimiter::for('app', fn (Request $r) => Limit::perMinute(120)->by('app|'.sha1((string) $r->bearerToken())));
        RateLimiter::for('randomise', fn (Request $r) => Limit::perMinute(10)->by('rand|'.($r->user()?->id ?? $r->ip())));
    }
}
