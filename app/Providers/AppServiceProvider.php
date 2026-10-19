<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Separate browser limits allow a whole classroom to share one network.
        RateLimiter::for('classroom-create', fn (Request $request) => [
            Limit::perMinute(5)->by('create-browser:'.$request->session()->getId()),
            Limit::perMinute(60)->by('create-ip:'.$request->ip()),
        ]);
        RateLimiter::for('classroom-join', fn (Request $request) => [
            Limit::perMinute(15)->by('join-browser:'.$request->session()->getId()),
            Limit::perMinute(240)->by('join-ip:'.$request->ip()),
        ]);
    }
}
