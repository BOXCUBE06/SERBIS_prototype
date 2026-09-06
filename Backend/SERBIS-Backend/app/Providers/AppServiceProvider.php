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
        self::assertOtpBypassIsUnsetInProduction();
        self::assertSmsFakeIsUnsetInProduction();

        // Auth throttling for /admin/login, /resident/login.
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

        // /register only. It used to share 'login' above, which is the wrong
        // shape for it: 'login' keys its tight 5/min limit on the submitted
        // email, but every registration attempt submits a NEW email by
        // definition, so that key never repeats and the tight limit never
        // engages — only the loose 20/min-per-IP fallback ever applied, and
        // being per-minute it resets forever, so a script sitting at 20/min
        // could create an unbounded number of accounts over a day. Each
        // registration also creates a `tbl_residents` row immediately and, if
        // the phone number is a real one, bills a real PhilSMS send before
        // anyone confirms the address — so both the row-spam and the billing
        // exposure scale with how long a script is left running, not with any
        // single burst. The per-hour tier is the actual fix; per-minute stays
        // as a fast-fail for a tight loop. Same CGNAT reasoning as 'login'
        // keeps this IP-only rather than adding an email key that would never
        // repeat here either.
        RateLimiter::for('register', function (Request $request) {
            // Distinct key suffixes per tier: ThrottleRequests builds its cache
            // key from the limiter name plus this ->by() value alone, with no
            // regard for decay — two tiers sharing one key would collide onto
            // the same bucket and count each request against both at once.
            return [
                Limit::perMinute(5)->by('ip-burst:'.$request->ip()),
                Limit::perHour(15)->by('ip-hour:'.$request->ip()),
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

    /**
     * Refuse to run a production deployment with the test-only OTP bypass
     * configured (config/serbis.php, AuthController::otpBypassMatches()).
     *
     * That method already refuses the bypass on its own by checking
     * app()->environment() at request time — this guard exists so a
     * misconfigured production server fails loudly at boot instead of
     * depending on that request-time check never being changed or bypassed
     * by a future edit. Same shape as assertDebugIsOffInProduction() above,
     * for the same reason: quietly clearing the config would leave the
     * variable still set in the .env on the server, so the next person to
     * read it learns the wrong thing about what is running.
     */
    public static function assertOtpBypassIsUnsetInProduction(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if ((string) config('serbis.otp_bypass_code', '') === '') {
            return;
        }

        throw new RuntimeException(
            'REFUSING TO START: SERBIS_OTP_BYPASS_CODE is set while APP_ENV is '
            .'production. This bypass exists only so local development and CI '
            .'test automation (Playwright) can skip real OTP delivery, and must '
            .'never be reachable in production. '
            .'Unset SERBIS_OTP_BYPASS_CODE in the .env on this server, then run '
            .'`php artisan config:clear` (or `config:cache`) and start again.'
        );
    }

    /**
     * Refuse to run a production deployment with the test-only SMS
     * suppression flag configured (config/serbis.php, PhilSms::send()).
     *
     * Same shape as assertOtpBypassIsUnsetInProduction() above, for the same
     * reason: that flag already refuses itself at request time
     * (PhilSms::fakingEnabled() checks app()->environment() too), and this
     * guard exists so a misconfigured production server fails loudly at boot
     * instead of depending on that request-time check never being changed.
     */
    public static function assertSmsFakeIsUnsetInProduction(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! config('serbis.sms_fake', false)) {
            return;
        }

        throw new RuntimeException(
            'REFUSING TO START: SERBIS_SMS_FAKE is set while APP_ENV is '
            .'production. This flag exists only so local development and CI '
            .'test automation (Playwright) can skip real, billed PhilSMS '
            .'sends, and must never be reachable in production. '
            .'Unset SERBIS_SMS_FAKE in the .env on this server, then run '
            .'`php artisan config:clear` (or `config:cache`) and start again.'
        );
    }
}
