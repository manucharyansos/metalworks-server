<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitorController extends Controller
{
    public function getDeviceStats(): JsonResponse
    {
        $totalVisitors = Visitor::count();

        // Device counts
        $desktopCount = Visitor::where('device', 'desktop')->count();
        $mobileCount = Visitor::where('device', 'mobile')->count();
        $tabletCount = Visitor::where('device', 'tablet')->count();

        return response()->json([
            'total' => $totalVisitors,
            'desktop' => [
                'count' => $desktopCount,
                'percentage' => $totalVisitors ? ($desktopCount / $totalVisitors) * 100 : 0
            ],
            'mobile' => [
                'count' => $mobileCount,
                'percentage' => $totalVisitors ? ($mobileCount / $totalVisitors) * 100 : 0
            ],
            'tablet' => [
                'count' => $tabletCount,
                'percentage' => $totalVisitors ? ($tabletCount / $totalVisitors) * 100 : 0
            ]
        ]);
    }

}
