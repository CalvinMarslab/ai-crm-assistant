<?php

namespace App\Http\Middleware;

use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request to the authenticated user's organization so every
 * organization-scoped model filters itself.
 */
class SetOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            OrganizationContext::set($user->organization_id);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        OrganizationContext::clear();
    }
}
