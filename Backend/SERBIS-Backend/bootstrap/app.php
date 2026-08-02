<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating proxy (any PaaS, or nginx in front of php-fpm)
        // every request arrives from the proxy over plain HTTP. Untrusted, that
        // means asset() and url() emit http:// links on an https:// site, and —
        // worse — throttleApi() keys its rate limit on the proxy's IP for every
        // unauthenticated request, so all callers share one 60/min bucket and
        // login stops working under any real load.
        //
        // Trusting '*' is the correct setting for a platform that assigns proxy
        // IPs dynamically, and is only safe because the app is not reachable
        // except through that proxy. If this is ever deployed somewhere the PHP
        // port is exposed directly, narrow this to the proxy's addresses —
        // otherwise a client can spoof X-Forwarded-For and pick its own rate
        // limit bucket.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->throttleApi('60,1');
        // API-only app: there is no `login` route to redirect a guest to, and
        // Laravel's default guest redirect resolves route('login') eagerly, so an
        // unauthenticated api/* request without an Accept: application/json header
        // died with RouteNotFoundException (500) instead of 401. Returning null
        // lets the exception handler render the 401 JSON.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login'),
        );
        $middleware->alias([
            'is.admin' => \App\Http\Middleware\IsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
