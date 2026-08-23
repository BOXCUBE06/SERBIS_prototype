<?php

use Illuminate\Support\Facades\Route;

/**
 * This application serves an API and nothing else. `/` used to render
 * Laravel's stock welcome page — 220 lines of Blade carrying an inlined
 * Tailwind build and an `@fonts` directive that fetches from a CDN — which is
 * dead weight on a host whose only job is to answer routes/api.php.
 *
 * Returning JSON also means the deployed image needs no Node stage: nothing
 * left in this app calls @vite, so there is no manifest to build.
 *
 * `/up` is Laravel's own health endpoint, registered in bootstrap/app.php and
 * what the platform's health check should point at. It is named here so that
 * anyone who lands on the root of the API is told where it is.
 */
Route::get('/', fn () => response()->json([
    'service' => 'SERBIS API',
    'health' => '/up',
]));
