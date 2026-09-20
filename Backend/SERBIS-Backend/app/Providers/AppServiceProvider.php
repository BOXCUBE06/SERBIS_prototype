<?php

namespace App\Providers;

use App\Services\Sms\SkySmsGateway;
use App\Services\Sms\SmsGateway;
use App\Support\PhoneNumber;
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
        // The one SMS vendor. Callers ask for the interface, so a provider
        // change is this line and one class.
        $this->app->singleton(SmsGateway::class, SkySmsGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        self::assertDebugIsOffInProduction();
        self::assertOtpBypassIsLocalOnly();
        self::assertSmsFakeIsUnsetInProduction();

        // Replaces the old flat throttleApi('60,1') (bootstrap/app.php used to
        // set this for the whole 'api' middleware group). Same shape Laravel's
        // own default 'api' limiter uses — keyed on the authenticated user
        // where there is one, IP otherwise — but named so routes/api.php can
        // attach it explicitly to the public/resident routes only. Admin
        // routes get 'admin-api' below instead of this one.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        // Admin panel routes (routes/api.php's is.admin group) — a higher
        // ceiling than the public 'api' limiter above, and a separate bucket
        // rather than stacked on top of it (P1 rate-limit audit, 2026-09-15):
        // a single admin working the request queue already fires several
        // requests per action (ServiceRequestQueue's list + master-list
        // refetches), and 60/min shared with public traffic meant ordinary
        // navigation could 429. Keyed on admin_id, same reasoning as
        // 'sms-blast' below — one office's CGNAT address must not throttle
        // every admin behind it as a single caller.
        //
        // $request->user()->admin_id used to be read unconditionally. In
        // practice it can't crash today — is.admin (App\Http\Middleware\
        // IsAdmin) sits between auth:sanctum and this limiter in every
        // request's final middleware order (see the comment above
        // Route::middleware(['auth:sanctum', 'is.admin', 'throttle:admin-api'])
        // in routes/api.php for how that's verified), so a non-admin never
        // reaches this closure at all — IsAdmin's 403 stops the pipeline
        // first. Written defensively anyway: no property read on a
        // possibly-null user, and a non-admin authenticated user (should
        // this ever run ahead of IsAdmin after some future reordering)
        // falls back to its own per-user key rather than colliding with
        // every other non-admin on '' or with a real admin's bucket.
        RateLimiter::for('admin-api', function (Request $request) {
            $user = $request->user();

            if ($user?->admin_id) {
                return Limit::perMinute(300)->by('admin:'.$user->admin_id);
            }

            return Limit::perMinute(300)->by($user ? 'user:'.$user->getAuthIdentifier() : $request->ip());
        });

        // Auth throttling for /admin/login, /resident/login.
        //
        // Keyed by submitted email first so one account under attack cannot lock out
        // everyone else behind the same IP. The looser IP limit is a fallback that still
        // caps scripted abuse. Both are needed: PH carriers use CGNAT, so a single public
        // IP can front an entire subscriber pool, and an IP-only limit would lock those
        // residents out of a disaster-response system.
        RateLimiter::for('login', function (Request $request) {
            // The account being attacked: a resident's phone number (in canonical
            // form, so "0917…" and "+63917…" share one bucket), or a staff
            // member's email address on the admin login.
            $phone = PhoneNumber::normalize((string) $request->input('phone_number'));
            $account = $phone !== ''
                ? 'phone:'.$phone
                : 'email:'.Str::lower((string) $request->input('email_address'));

            return [
                Limit::perMinute(5)->by($account.'|'.$request->ip()),
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
        // the phone number is a real one, bills a real SkySMS send before
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
        // POST /sms/blast, the only endpoint that spends money. Named rather than
        // an inline throttle:3,60 - see the ->by() note under 'register' above: an
        // inline limit reuses ThrottleRequests' route signature, which is the same
        // signature the api group's own throttleApi('60,1') already counts on, so
        // every request would increment one shared bucket twice and three sends an
        // hour became closer to one. Keyed on the account for the reason
        // SmsController::assertCurrentPassword is: an office on one CGNAT address
        // must not be able to spend a colleague's allowance.
        // POST /admin/change-password checks the current password, so it is a
        // guessing target for anyone holding a token. Keyed on the account.
        // Forgotten password (three public steps). Keyed on the canonical phone
        // number, NOT on whether an account holds it: a limit that only bit real
        // accounts would be a way to find them. The per-hour tier is per number
        // whatever the caller's address, since a text to one number is a text to
        // one person however many addresses ask; the IP tier bounds one caller
        // across many numbers (SMS is billed).
        RateLimiter::for('password-reset', function (Request $request) {
            $phone = PhoneNumber::normalize((string) $request->input('phone_number'));
            $number = $phone !== '' ? hash('sha256', $phone) : 'malformed';

            return [
                Limit::perMinute(5)->by('pwreset-minute:'.$number.'|'.$request->ip()),
                Limit::perHour(10)->by('pwreset-hour:'.$number),
                Limit::perHour(30)->by('pwreset-ip:'.$request->ip()),
            ];
        });

        // The three steps of moving a resident's phone number. Keyed on the
        // account: an office or a CGNAT address must not share a budget, and the
        // per-code attempt cap is the real brake on guessing.
        RateLimiter::for('phone-change', function (Request $request) {
            $who = 'phone-change:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute(5)->by($who.'|minute'),
                Limit::perHour(15)->by($who.'|hour'),
            ];
        });

        RateLimiter::for('password-change', function (Request $request) {
            return Limit::perMinute(5)->by('password-change:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        RateLimiter::for('sms-blast', function (Request $request) {
            return Limit::perHour(3)->by('admin:'.$request->user()->admin_id);
        });
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
     * Refuse to run anywhere but `local` with the test-only OTP bypass
     * configured (config/serbis.php, AuthController::otpBypassMatches()).
     *
     * That method already refuses the bypass outside `local` at request time;
     * this guard makes a misconfigured server fail loudly at boot instead of
     * depending on that check never being edited. Same shape as
     * assertDebugIsOffInProduction() above: quietly clearing the config would
     * leave the variable set in the server's .env, misleading the next reader.
     */
    public static function assertOtpBypassIsLocalOnly(): void
    {
        if (app()->environment('local')) {
            return;
        }

        if ((string) config('serbis.otp_bypass_code', '') === '') {
            return;
        }

        throw new RuntimeException(
            'REFUSING TO START: SERBIS_OTP_BYPASS_CODE is set while APP_ENV is '
            .app()->environment().'. This bypass exists only so local development '
            .'can skip real OTP delivery, and must never be reachable on a server '
            .'residents can use. '
            .'Unset SERBIS_OTP_BYPASS_CODE in the .env on this server, then run '
            .'`php artisan config:clear` (or `config:cache`) and start again.'
        );
    }

    /**
     * Refuse to run a production deployment with the test-only SMS
     * suppression flag configured (config/serbis.php, SkySmsGateway).
     *
     * Same shape as assertOtpBypassIsLocalOnly() above, for the same
     * reason: that flag already refuses itself at request time
     * (SkySmsGateway::fakingEnabled() checks app()->environment() too), and this
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
            .'test automation (Playwright) can skip real, billed SkySMS '
            .'sends, and must never be reachable in production. '
            .'Unset SERBIS_SMS_FAKE in the .env on this server, then run '
            .'`php artisan config:clear` (or `config:cache`) and start again.'
        );
    }
}
