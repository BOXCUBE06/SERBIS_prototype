<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response hardening for every answer this API gives. None of it touches CORS
 * (HandleCors still owns those headers) or what a fetch() caller can read.
 *
 * Registered first in bootstrap/app.php so preflights, 401s, 429s and
 * unmatched routes carry the headers too.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ]);

        // Not on an HTML answer (/up, the conduction-request print view): 'none'
        // would strip its inline styles and scripts. Everything else is JSON or a file.
        if (! str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        // Only over TLS: the proxy's X-Forwarded-Proto is trusted in bootstrap/app.php.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // The image sets expose_php=Off; this covers a host that does not.
        header_remove('X-Powered-By');

        return $response;
    }
}
