<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Auth throttling for /register, /admin/login, /resident/login.
        //
        // Keyed by submitted email first so one account under attack cannot lock out
        // everyone else behind the same IP. The looser IP limit is a fallback that still
        // caps scripted abuse. Both are needed: PH carriers use CGNAT, so a single public
        // IP can front an entire subscriber pool, and an IP-only limit would lock those
        // residents out of a disaster-response system.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email_address'));

            return [
                Limit::perMinute(5)->by('email:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('ip:'.$request->ip()),
            ];
        });
    }
}
