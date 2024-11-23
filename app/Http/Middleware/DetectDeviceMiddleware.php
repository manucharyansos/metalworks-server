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

        // Log the detected device
        \Log::info("Device detected: $device");

        // Store visitor data
        Visitor::create([
            'device' => $device,
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }

}
