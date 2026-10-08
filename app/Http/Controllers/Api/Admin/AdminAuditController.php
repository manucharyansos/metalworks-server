<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdminAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:60'],
            'action' => ['nullable', 'string', 'max:150'],
            'subject_type' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        if (!Schema::hasTable('activity_logs')) {
            return response()->json([
                'message' => 'Activity log migration-ը դեռ կիրառված չէ։',
                'migration_required' => true,
            ], 409);
        }

        try {
            $query = $this->staffActivityQuery()->with([
                'user.role:id,name,value',
                'user.factory:id,name',
                'user.worker:id,user_id,last_name,phone',
            ]);

            if (!empty($validated['search'])) {
                $search = trim($validated['search']);
                $query->where(function (Builder $q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('subject_label', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('subject_id', 'like', "%{$search}%")
                        ->orWhereHas('user', function (Builder $userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhereHas('worker', fn (Builder $workerQuery) => $workerQuery
                                    ->where('last_name', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%"));
                        });
                });
            }

            if (!empty($validated['user_id'])) {
                $query->where('user_id', (int) $validated['user_id']);
            }

            if (!empty($validated['role'])) {
                $role = $validated['role'];
                $query->whereHas('user', fn (Builder $userQuery) => $userQuery->forRoles([$role]));
            }

            if (!empty($validated['category'])) {
                $query->where('category', $validated['category']);
            }

            if (!empty($validated['action'])) {
                $query->where('action', $validated['action']);
            }

            if (!empty($validated['subject_type'])) {
                $query->where('subject_type', $validated['subject_type']);
            }

            if (!empty($validated['date_from'])) {
                $query->where('created_at', '>=', Carbon::parse($validated['date_from'])->startOfDay());
            }

            if (!empty($validated['date_to'])) {
                $query->where('created_at', '<=', Carbon::parse($validated['date_to'])->endOfDay());
            }

            $logs = $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate((int) ($validated['per_page'] ?? 40));

            $items = collect($logs->items())->map(function (ActivityLog $row) {
                $user = $row->user;
                $lastName = $user?->last_name ?: $user?->worker?->last_name;
                $displayName = trim(implode(' ', array_filter([$user?->name, $lastName])));

                return [
                    'id' => $row->id,
                    'user_id' => $row->user_id,
                    'category' => $row->category,
                    'action' => $row->action,
                    'method' => $row->method,
                    'route' => $row->route,
                    'subject_type' => $row->subject_type,
                    'subject_id' => $row->subject_id,
                    'subject_label' => $row->subject_label,
                    'description' => $row->description,
                    'meta' => $row->meta,
                    'created_at' => $row->created_at?->toIso8601String(),
                    'user' => $user ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'display_name' => $displayName !== '' ? $displayName : $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone ?: $user->worker?->phone,
                        'role' => $user->role ? [
                            'name' => $user->role->name,
                            'value' => $user->role->value,
                        ] : null,
                        'factory' => $user->factory ? [
                            'id' => $user->factory->id,
                            'name' => $user->factory->name,
                        ] : null,
                    ] : null,
                ];
            })->values();

            $actorIds = $this->staffActivityQuery()
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id');

            $actors = User::query()
                ->with([
                    'role:id,name,value',
                    'factory:id,name',
                    'worker:id,user_id,last_name,phone',
                ])
                ->whereIn('id', $actorIds)
                ->orderBy('name')
                ->get()
                ->map(function (User $user) {
                    $lastName = $user->last_name ?: $user->worker?->last_name;
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'display_name' => trim(implode(' ', array_filter([$user->name, $lastName]))) ?: $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone ?: $user->worker?->phone,
                        'role' => $user->role ? [
                            'name' => $user->role->name,
                            'value' => $user->role->value,
                        ] : null,
                        'factory' => $user->factory ? [
                            'id' => $user->factory->id,
                            'name' => $user->factory->name,
                        ] : null,
                    ];
                })
                ->values();

            $today = now()->startOfDay();
            $sevenDaysAgo = now()->copy()->subDays(6)->startOfDay();

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
                'summary' => [
                    'today' => $this->staffActivityQuery()->where('created_at', '>=', $today)->count(),
                    'last_7_days' => $this->staffActivityQuery()->where('created_at', '>=', $sevenDaysAgo)->count(),
                    'files_today' => $this->staffActivityQuery()->where('category', 'files')->where('created_at', '>=', $today)->count(),
                    'orders_today' => $this->staffActivityQuery()->whereIn('category', ['orders', 'production'])->where('created_at', '>=', $today)->count(),
                ],
                'filters' => [
                    'actors' => $actors,
                    'roles' => $actors
                        ->pluck('role')
                        ->filter()
                        ->unique('name')
                        ->values(),
                    'categories' => $this->staffActivityQuery()
                        ->whereNotNull('category')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category')
                        ->values(),
                    'actions' => $this->staffActivityQuery()
                        ->whereNotNull('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action')
                        ->values(),
                ],
                'server_time' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin activity audit failed', ['exception' => $e]);

            return response()->json([
                'message' => 'Unable to load employee activity history.',
            ], 500);
        }
    }

    private function staffActivityQuery(): Builder
    {
        return ActivityLog::query()->where(function (Builder $query) {
            $query->whereNull('user_id')
                ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->forRoles(['authenticatedUser', 'guestUser'], true));
        });
    }
}
