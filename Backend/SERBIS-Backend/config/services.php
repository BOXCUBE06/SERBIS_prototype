<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'philsms' => [
        'token' => env('PHILSMS_TOKEN'),
        // The shared sender PhilSMS gives every account. A dedicated sender id
        // needs telco approval and is refused for academic projects, so this
        // stays until the agency itself applies for one.
        'sender_id' => env('PHILSMS_SENDER_ID', 'PhilSMS'),
    ],

    // SkySMS. Credits, not a subscription: one credit is one 160-character
    // message, and links or profanity cost 10-50 each while not being
    // delivered — see App\Services\Sms\SmsMessagePolicy. The account allows
    // 30 messages a minute and 3 a second, shared by every key on it.
    'skysms' => [
        'api_key' => env('SKYSMS_API_KEY'),
        'base_url' => env('SKYSMS_BASE_URL', 'https://skysms.skyio.site/api/v1'),
        // Loops that text people one by one (reminders, availability notices)
        // wait this long between sends, which holds them at 30 a minute, and
        // retry a 429 with a doubling wait starting from the base below.
        'pace_seconds' => (float) env('SKYSMS_PACE_SECONDS', 2),
        'retry_base_seconds' => (float) env('SKYSMS_RETRY_BASE_SECONDS', 2),
        'max_retries' => (int) env('SKYSMS_MAX_RETRIES', 3),
    ],

    'firebase' => [
        // Absolute path to the service-account JSON downloaded from Firebase
        // Console -> Project Settings -> Service Accounts. The file itself
        // carries the project id — nothing else to configure here. Never
        // commit the file this points at.
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
