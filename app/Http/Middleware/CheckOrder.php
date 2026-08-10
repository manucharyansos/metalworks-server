<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckOrder
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($user->role?->name !== 'manager') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
