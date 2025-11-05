<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $arg1, ?string $arg2 = null)
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        if ($user->role && $user->role->name === 'admin') {
            return $next($request);
        }

        if ($arg2 === null) {
            $slug = $arg1;
        } else {
            $slug = "{$arg1}.{$arg2}";
        }

        if (! method_exists($user, 'hasPermission')) {
            abort(500, 'User::hasPermission method is missing');
        }

        if (! $user->hasPermission($slug)) {
            abort(403, "Դուք չունեք '{$slug}' թույլտվություն");
        }

        return $next($request);
    }
}
