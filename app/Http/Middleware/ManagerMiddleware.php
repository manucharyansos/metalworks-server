<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class ManagerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next): mixed
    {
        $user = Auth::user();
        $role = $user->role->name ?? $user->role ?? null;

        if (!$user || $role !== 'manager') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
