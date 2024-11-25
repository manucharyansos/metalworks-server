<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Visitor;

class DetectDeviceMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $userAgent = $request->header('User-Agent');
        $device = 'unknown';

        if (preg_match('/mobile|android|iphone|ipad/i', $userAgent)) {
            $device = 'mobile';
        } elseif (preg_match('/tablet|ipad/i', $userAgent)) {
            $device = 'tablet';
        } elseif (preg_match('/windows|macintosh|linux/i', $userAgent)) {
            $device = 'desktop';
        }

        $ip = $request->ip();

        // Check if the visitor has already been logged
        if (!Visitor::where('ip', $ip)->exists()) {
            Visitor::create([
                'device' => $device,
                'ip' => $ip,
            ]);
        }

        return $next($request);
    }


}
