<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Test-only OTP bypass
    |--------------------------------------------------------------------------
    |
    | A fixed code AuthController::residentLoginVerify() accepts in place of
    | the real, randomly generated login code, so an automated client
    | (Playwright, CI) can finish mobile login without reading the SMS or
    | email a real code goes to.
    |
    | Unset by default — env('SERBIS_OTP_BYPASS_CODE') with no fallback, not
    | an empty-string default, so a truthiness check on the config value alone
    | tells you whether the bypass is live. Never set this outside a
    | developer's own .env or a CI job's environment.
    |
    | Refused outright in production regardless of this value — see
    | AuthController::otpBypassMatches(), which also checks
    | app()->environment(), and AppServiceProvider::assertOtpBypassIsUnsetInProduction(),
    | which stops the app booting at all if the variable is set there. Two
    | independent checks on purpose: a boot-time guard that only fires once
    | is not a substitute for the request-time check being right every time,
    | and vice versa.
    |
    */

    'otp_bypass_code' => env('SERBIS_OTP_BYPASS_CODE'),

    /*
    |--------------------------------------------------------------------------
    | Test-only SMS suppression
    |--------------------------------------------------------------------------
    |
    | When true, PhilSms::send() skips the real HTTP call entirely and returns
    | a synthetic success response instead, so an E2E run (Playwright, CI)
    | never bills a real handset. Every caller — the admin text blast and the
    | OTP paths — goes through PhilSms::send(), so this one flag covers all of
    | them without touching AuthController or SmsController.
    |
    | Defaults false via env('SERBIS_SMS_FAKE', false), not just env() with no
    | fallback, because a falsy default has to survive a env() cast quirk: an
    | unset variable and a variable literally set to "false" both have to mean
    | "do not fake". Never set this outside a developer's own .env or a CI
    | job's environment.
    |
    | Refused outright in production regardless of this value — see
    | PhilSms::send(), which also checks app()->environment(), and
    | AppServiceProvider::assertSmsFakeIsUnsetInProduction(), which stops the
    | app booting at all if the variable is set there. Same two-guard shape as
    | otp_bypass_code above, for the same reason.
    |
    */

    'sms_fake' => env('SERBIS_SMS_FAKE', false),

];
