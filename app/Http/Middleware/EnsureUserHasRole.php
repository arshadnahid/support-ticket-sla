<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role gate, e.g. ->middleware('role:admin,agent').
 * Fine-grained, per-ticket rules belong in policies.
 */
class EnsureUserHasRole
{
    /**
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role) => UserRole::from($role), $roles);

        if (! $request->user()?->hasRole(...$allowed)) {
            throw new AuthorizationException();
        }

        return $next($request);
    }
}
