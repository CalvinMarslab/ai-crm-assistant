<?php

namespace App\Http\Middleware;

use App\Support\OrganizationClock;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request to the authenticated user's organization so every
 * organization-scoped model filters itself.
 *
 * This MUST run before SubstituteBindings: route-model binding queries the
 * database, and without the context set those queries carry no tenant filter,
 * which would resolve records belonging to any organization.
 */
class SetOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolved explicitly through the token guard because this runs ahead of
        // the route's auth middleware, so the default guard may not be set yet.
        $user = $request->user('sanctum') ?? $request->user();

        OrganizationContext::set($user?->organization_id);
        app(OrganizationClock::class)->reset();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        OrganizationContext::clear();
    }
}
