<?php

namespace App\Http\Middleware;

use App\Exceptions\AccountInactiveException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects tokens that belong to a user deactivated after the token was issued.
 */
class EnsureUserIsActive
{
    /**
     * @throws AccountInactiveException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            throw new AccountInactiveException();
        }

        return $next($request);
    }
}
