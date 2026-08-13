<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:150'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        try {
            $query = OrderLog::query()->with([
                'user:id,name,email',
                'order:id,name,status',
                'order.orderNumber:id,order_id,number',
                'order.prefixCode:id,order_id,code',
            ]);

            if (!empty($validated['search'])) {
                $search = trim($validated['search']);
                $query->where(function (Builder $q) use ($search) {
                    $q->where('message', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhereHas('user', function (Builder $userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('order', fn (Builder $orderQuery) => $orderQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('order.orderNumber', fn (Builder $numberQuery) => $numberQuery->where('number', 'like', "%{$search}%"))
                        ->orWhereHas('order.prefixCode', fn (Builder $prefixQuery) => $prefixQuery->where('code', 'like', "%{$search}%"));
                });
            }

            if (!empty($validated['user_id'])) {
                $query->where('user_id', (int) $validated['user_id']);
            }

            if (!empty($validated['action'])) {
                $query->where('action', $validated['action']);
            }

            if (!empty($validated['order_id'])) {
                $query->where('order_id', (int) $validated['order_id']);
            }

            if (!empty($validated['date_from'])) {
                $query->whereDate('created_at', '>=', $validated['date_from']);
            }

            if (!empty($validated['date_to'])) {
                $query->whereDate('created_at', '<=', $validated['date_to']);
            }

            $logs = $query->latest('id')->paginate((int) ($validated['per_page'] ?? 30));

            $items = collect($logs->items())->map(function (OrderLog $row) {
                return [
                    'id' => $row->id,
                    'order_id' => $row->order_id,
                    'user_id' => $row->user_id,
                    'action' => $row->action,
                    'message' => $row->message,
                    'meta' => $row->meta,
                    'created_at' => $row->getRawOriginal('created_at'),
                    'user' => $row->user ? [
                        'id' => $row->user->id,
                        'name' => $row->user->name,
                        'email' => $row->user->email,
                    ] : null,
                    'order' => $row->order ? [
                        'id' => $row->order->id,
                        'name' => $row->order->name,
                        'status' => $row->order->status,
                        'number' => $row->order->orderNumber?->number,
                        'prefix' => $row->order->prefixCode?->code,
                    ] : null,
                ];
            })->values();

            $actorIds = OrderLog::query()
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id');

            return response()->json([
                'logs' => $items,
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'total' => $logs->total(),
                    'per_page' => $logs->perPage(),
                    'last_page' => $logs->lastPage(),
                    'from' => $logs->firstItem(),
                    'to' => $logs->lastItem(),
                ],
                'filters' => [
                    'actors' => User::query()
                        ->whereIn('id', $actorIds)
                        ->orderBy('name')
                        ->get(['id', 'name', 'email']),
                    'actions' => OrderLog::query()
                        ->whereNotNull('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action')
                        ->values(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin audit log failed', ['exception' => $e]);

            return response()->json([
                'message' => 'Unable to load activity history.',
            ], 500);
        }
    }
}
