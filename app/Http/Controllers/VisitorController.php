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

        // Կանխատեսված սարքերի տոկոսներ
        $desktopVisitors = Visitor::where('device', 'desktop')->count();
        $mobileVisitors = Visitor::where('device', 'mobile')->count();
        $tabletVisitors = Visitor::where('device', 'tablet')->count();

        return response()->json([
            'desktop' => ($desktopVisitors / $totalVisitors) * 100,
            'mobile' => ($mobileVisitors / $totalVisitors) * 100,
            'tablet' => ($tabletVisitors / $totalVisitors) * 100,
        ]);
    }
}
