<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EngineerMiddleware
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

        if (!$user || $role !== 'engineer') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
