<?php

namespace App\Http\Middleware;

use Closure;

class AuthenticatedUserMiddleware
{
    public function handle($request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($user->role?->name !== 'authenticatedUser') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
