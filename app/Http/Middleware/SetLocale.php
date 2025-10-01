<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $loc = $request->header('X-Locale')
            ?: $request->query('locale')
                ?: $request->query('lang')
                    ?: config('app.locale');

        $loc = in_array($loc, ['hy','ru','en']) ? $loc : config('app.locale');
        app()->setLocale($loc);

        return $next($request);
    }
}
