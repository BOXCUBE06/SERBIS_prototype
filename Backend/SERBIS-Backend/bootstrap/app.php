<?php

use App\Http\Middleware\EnsureSection;
use App\Http\Middleware\IsAdmin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // worse — the 'api' RateLimiter (routes/api.php, AppServiceProvider.php)
        // falls back to $request->ip() for every unauthenticated request, so an
        // untrusted proxy IP would put every caller behind it in one bucket and
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

        // No flat throttleApi() here on purpose — routes/api.php attaches
        // 'throttle:api' (public + resident routes) or 'throttle:admin-api'
        // (the is.admin group) explicitly instead, so admin traffic gets its
        // own higher ceiling rather than sharing the public one (P1 rate-limit
        // audit, 2026-09-15: a single admin clicking through the request queue
        // alone could exceed a flat 60/min).
        // API-only app: there is no `login` route to redirect a guest to, and
        // Laravel's default guest redirect resolves route('login') eagerly, so an
        // unauthenticated api/* request without an Accept: application/json header
        // died with RouteNotFoundException (500) instead of 401. Returning null
        // lets the exception handler render the 401 JSON.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login'),
        );
        // Route-model binding would otherwise 404 a missing id before is.admin
        // answers 403, telling a resident which admin-route ids exist.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: IsAdmin::class);
        $middleware->alias([
            'is.admin' => IsAdmin::class,
            'section' => EnsureSection::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // What a client may learn about a failure it did not cause. Rendering
        // only: reporting is untouched, so the full error still reaches the log.
        // Debug stays detailed for local work; production refuses to boot with
        // it on (AppServiceProvider). A message a controller wrote on purpose
        // (abort(404, 'Vehicle not found')) is kept — only the framework's own
        // wording is replaced.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*') || config('app.debug')) {
                return null;
            }

            // findOrFail's "No query results for model [App\Models\X] 5" names an
            // internal class; an unknown URL echoes the route back.
            if ($e->getPrevious() instanceof ModelNotFoundException || str_starts_with($e->getMessage(), 'The route ')) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            return null;
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || config('app.debug')) {
                return null;
            }

            // These have their own, user-facing rendering (422, 401, a response
            // the code built itself, or any deliberate 4xx).
            if ($e instanceof ValidationException || $e instanceof AuthenticationException || $e instanceof HttpResponseException) {
                return null;
            }
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json(['message' => 'Something went wrong. Please try again.'], $status);
        });
    })->create();
