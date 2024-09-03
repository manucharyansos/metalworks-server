<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckOrder
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            \Log::info('User not authenticated');
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $user = auth()->user();
        if (!$user->can('access_order')) {
            \Log::info('User does not have access_order permission');
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
