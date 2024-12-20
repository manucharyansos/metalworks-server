<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CheckOrder
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
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $user = Auth::user();

        $role = $user->role;

        if ($role && $role->name !== 'manager') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return $next($request);
    }

}
