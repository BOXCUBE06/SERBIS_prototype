<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('ADMIN_FRONTEND_URL', 'https://admin.yourdomain.gov.ph'),

        // Local development only. The Flutter app's web build is served from its
        // own port, so without this every API call it makes is blocked by the
        // browser before it reaches Laravel. Guarded on APP_ENV rather than
        // app()->environment(), which is not resolvable this early: the container
        // binds 'env' only after every config file has been loaded.
        env('APP_ENV') === 'local' ? env('MOBILE_DEV_URL') : null,
    ]),

    'allowed_origins_patterns' => [
        // Vercel gives every push its own generated hostname, so a preview
        // deploy can never be named in ADMIN_FRONTEND_URL ahead of time. Two
        // shapes exist and both are matched here:
        //
        //   serbis-prototype-1zky96pn2-absolute3-js.vercel.app   (deployment)
        //   serbis-prototype-git-main-absolute3-js.vercel.app    (branch alias)
        //
        // The middle segment allows hyphens because a branch alias puts the
        // branch name there. It stays pinned between this project's prefix and
        // this team's slug, so it admits only our own previews - not every
        // *.vercel.app site. Team slug is 'absolute3-js', hyphen included; it
        // is not the 'Absolute3Js' display name.
        //
        // The production host, serbis-prototype.vercel.app, deliberately does
        // NOT match: it has no middle segment and is named outright by
        // ADMIN_FRONTEND_URL in allowed_origins above.
        '#^https://serbis-prototype-[a-z0-9-]+-absolute3-js\.vercel\.app$#i',
    ],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Idempotency-Key'],

    // Retry-After is not a CORS-safelisted response header, so without this the
    // admin panel cannot read it and has to guess at the throttle window.
    'exposed_headers' => ['Retry-After'],

    'max_age' => 0,

    'supports_credentials' => false,

];
