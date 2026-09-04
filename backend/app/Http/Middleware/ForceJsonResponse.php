<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This API only ever speaks JSON, so it declares that on the request's behalf.
 *
 * Without it, a request that arrives without an Accept header — a browser
 * following a download link, or a misconfigured client — is treated as a
 * browser request on failure, and an authentication error becomes a redirect
 * to a "login" route this application does not have, surfacing as a 500
 * instead of a 401.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
