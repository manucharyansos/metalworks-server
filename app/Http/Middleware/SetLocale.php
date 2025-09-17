<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

class SetLocale
{
    public function handle($request, Closure $next)
    {
        $locale = $request->header('X-Locale')
            // 2) կամ query-ից ?lang=hy
            ?? $request->query('lang')
            // 3) կամ session-ից / default-ից
            ?? session('locale', config('app.locale', 'hy'));

        $allowed = ['hy','ru','en'];
        if (! in_array($locale, $allowed, true)) {
            $locale = config('app.fallback_locale', 'hy');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
