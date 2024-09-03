<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckLaserCutting
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {

        if (!auth()->check() || !auth()->user()->can('access_laser_cutting')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}

