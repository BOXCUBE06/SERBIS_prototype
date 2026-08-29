<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

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
        self::assertDebugIsOffInProduction();

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

        // MFA verify/resend carry a challenge_id, not an email_address. Reusing
        // 'login' above would key every one of them on 'email:|ip:X' — the SAME
        // empty-email bucket for every admin and resident behind one IP, capped
        // at 5/min combined. An office on one connection would lock each other
        // out of finishing a login. Keyed by challenge_id instead: it identifies
        // one login attempt as precisely as an email identifies one account, and
        // the challenge's own 5-attempt cap (AuthController::consumeMfaChallengeAttempt)
        // is still the real brake — this is defence in depth, same as 'login' is.
        RateLimiter::for('mfa', function (Request $request) {
            $challenge = Str::lower((string) $request->input('challenge_id'));

            return [
                Limit::perMinute(10)->by('challenge:'.$challenge.'|'.$request->ip()),
                Limit::perMinute(20)->by('ip:'.$request->ip()),
            ];
        });
    }

    /**
     * Refuse to run a production deployment with debug mode on.
     *
     * .env.example ships APP_DEBUG=false, but a template only protects the
     * person who copies it. Somebody debugging a live incident sets true, gets
     * their answer and does not set it back, and from then on every API caller
     * — including one who is not supposed to be there — is handed stack traces,
     * absolute file paths, the SQL that failed and the values bound into it.
     * Nothing about the running app looks wrong afterwards, which is why this
     * has to be the deployment failing rather than a warning in a log.
     *
     * Deliberately fatal rather than a forced config('app.debug', false).
     * Quietly correcting it would leave the .env on the server still saying
     * true, so the next person to read it learns the wrong thing about what the
     * server is doing.
     *
     * Public and static so it can be tested without booting a second
     * application; boot() calls it before anything else.
     */
    public static function assertDebugIsOffInProduction(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! config('app.debug')) {
            return;
        }

        throw new RuntimeException(
            'REFUSING TO START: APP_DEBUG is true while APP_ENV is production. '
            .'Debug mode exposes stack traces, file paths and SQL — including '
            .'query bindings — to anyone who can reach this API. '
            .'Set APP_DEBUG=false in the .env on this server, then run '
            .'`php artisan config:clear` (or `config:cache`) and start again.'
        );
    }
}
