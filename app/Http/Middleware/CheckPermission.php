<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $module = null, string $action = null)
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        if ($user->role && $user->role->name === 'admin') {
            return $next($request);
        }

        if (!$module) {
            $module = $request->segment(2) ?? 'unknown';
        }

        if (!$action) {
            $action = match ($request->method()) {
                'GET'          => 'view',
                'POST'         => 'create',
                'PUT', 'PATCH' => 'update',
                'DELETE'       => 'delete',
                default        => 'view',
            };
        }

        $permission = "{$module}.{$action}";

        if (!$user->hasPermission($permission)) {
            abort(403, "Դուք չունեք '{$permission}' թույլտվություն");
        }

        return $next($request);
    }
}
