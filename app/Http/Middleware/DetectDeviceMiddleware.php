<?php

namespace App\Http\Middleware;

use App\Models\Visitor;
use Closure;
use Illuminate\Http\Request;

class DetectDeviceMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // This middleware is globally registered and some legacy route groups
        // also reference its alias. Make repeated execution on the same
        // request a no-op instead of issuing duplicate visitor queries.
        if ($request->attributes->get('_visitor_device_detected') === true) {
            return $next($request);
        }
        $request->attributes->set('_visitor_device_detected', true);

        $userAgent = (string) $request->header('User-Agent', '');
        $device = 'unknown';

        // Check tablet before mobile because iPad historically matched the
        // broader mobile expression first and distorted the analytics.
        if (preg_match('/tablet|ipad/i', $userAgent)) {
            $device = 'tablet';
        } elseif (preg_match('/mobile|android|iphone/i', $userAgent)) {
            $device = 'mobile';
        } elseif (preg_match('/windows|macintosh|linux/i', $userAgent)) {
            $device = 'desktop';
        }

        $ip = $request->ip();
        $appKey = (string) config('app.key');

        // Avoid storing a raw network identifier. APP_KEY is required for a
        // stable, non-reversible keyed fingerprint. If it is unexpectedly
        // unavailable, skip analytics rather than persist the raw IP.
        if ($ip && $appKey !== '') {
            $fingerprint = rtrim(strtr(
                base64_encode(hash_hmac('sha256', $ip, $appKey, true)),
                '+/',
                '-_'
            ), '=');

            if (!Visitor::where('ip', $fingerprint)->exists()) {
                Visitor::create([
                    'device' => $device,
                    'ip' => $fingerprint,
                ]);
            }
        }

        return $next($request);
    }
}
