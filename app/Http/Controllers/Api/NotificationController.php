<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaskNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $mine = fn () => TaskNotification::where('user_id', $request->user()->id);
        $limit = max(1, min((int) $request->query('limit', 30), 100));

        return response()->json([
            'data' => $mine()->orderByDesc('id')->limit($limit)->get(),
            'unread_count' => $mine()->whereNull('read_at')->count(),
        ]);
    }

    /** Marks the given notifications (or all of them) as read. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'sometimes|array|max:200', 'ids.*' => 'integer']);
        TaskNotification::where('user_id', $request->user()->id)->whereNull('read_at')
            ->when(isset($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json([
            'unread_count' => TaskNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }
}
