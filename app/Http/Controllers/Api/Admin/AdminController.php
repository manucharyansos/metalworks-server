<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FactoryOrder;
use App\Models\FactoryOrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    private const CLOSED_ORDER_STATUSES = ['completed', 'canceled', 'cancelled'];

    private const DONE_FACTORY_STATUSES = ['finished', 'completed', 'done', 'confirmed'];

    private const CANCELED_FACTORY_STATUSES = ['canceled', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        return $this->dashboard($request);
    }

    public function dashboard(Request $request): JsonResponse
    {
        try {
            $today = now()->startOfDay();
            $tomorrow = (clone $today)->addDay();
            $weekEnd = (clone $today)->addDays(7)->endOfDay();

            $summary = [
                'total_orders' => Order::count(),
                'active_orders' => Order::query()
                    ->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
                    ->count(),
                'completed_orders' => Order::query()
                    ->where('status', 'completed')
                    ->count(),
                'canceled_orders' => Order::query()
                    ->whereIn('status', ['canceled', 'cancelled'])
                    ->count(),
                'overdue_orders' => Order::query()
                    ->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
                    ->whereHas('dates', fn (Builder $query) => $query->where('finish_date', '<', now()))
                    ->count(),
                'due_today' => Order::query()
                    ->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
                    ->whereHas('dates', fn (Builder $query) => $query->whereDate('finish_date', $today->toDateString()))
                    ->count(),
                'due_next_7_days' => Order::query()
                    ->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
                    ->whereHas('dates', fn (Builder $query) => $query
                        ->where('finish_date', '>=', $tomorrow)
                        ->where('finish_date', '<=', $weekEnd))
                    ->count(),
                'without_deadline' => Order::query()
                    ->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
                    ->where(function (Builder $query) {
                        $query->whereDoesntHave('dates')
                            ->orWhereHas('dates', fn (Builder $dateQuery) => $dateQuery->whereNull('finish_date'));
                    })
                    ->count(),
                'unassigned_factory_steps' => $this->openFactoryOrders()
                    ->whereNull('operator_id')
                    ->count(),
                'awaiting_admin_confirmation' => FactoryOrder::query()
                    ->whereIn('status', self::DONE_FACTORY_STATUSES)
                    ->whereNull('admin_confirmation_date')
                    ->count(),
                'factories' => Factory::count(),
                'factory_operators' => User::query()->whereNotNull('factory_id')->count(),
            ];

            $factories = Factory::query()
                ->with(['operators:id,name,role_id,factory_id'])
                ->orderBy('name')
                ->get(['id', 'name', 'value'])
                ->map(function (Factory $factory) use ($today) {
                    $openQuery = $this->openFactoryOrders()->where('factory_id', $factory->id);

                    $active = (clone $openQuery)->count();
                    $unassigned = (clone $openQuery)->whereNull('operator_id')->count();
                    $overdue = (clone $openQuery)
                        ->whereHas('order.dates', fn (Builder $query) => $query->where('finish_date', '<', now()))
                        ->count();
                    $dueToday = (clone $openQuery)
                        ->whereHas('order.dates', fn (Builder $query) => $query->whereDate('finish_date', $today->toDateString()))
                        ->count();
                    $awaitingAdmin = FactoryOrder::query()
                        ->where('factory_id', $factory->id)
                        ->whereIn('status', self::DONE_FACTORY_STATUSES)
                        ->whereNull('admin_confirmation_date')
                        ->count();
                    $completed30d = FactoryOrder::query()
                        ->where('factory_id', $factory->id)
                        ->whereNotNull('admin_confirmation_date')
                        ->where('admin_confirmation_date', '>=', now()->subDays(30))
                        ->count();

                    return [
                        'id' => $factory->id,
                        'name' => $factory->name,
                        'value' => $factory->value,
                        'operators_count' => $factory->operators->count(),
                        'active_orders' => $active,
                        'overdue_orders' => $overdue,
                        'due_today' => $dueToday,
                        'unassigned_orders' => $unassigned,
                        'awaiting_admin_confirmation' => $awaitingAdmin,
                        'completed_30d' => $completed30d,
                        'health' => $this->factoryHealth($active, $overdue, $unassigned, $awaitingAdmin),
                    ];
                })
                ->values();

            $operators = User::query()
                ->with(['role:id,name,value', 'factory:id,name,value'])
                ->whereNotNull('factory_id')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role_id', 'factory_id'])
                ->map(function (User $operator) use ($today) {
                    $openQuery = $this->openFactoryOrders()->where('operator_id', $operator->id);
                    $active = (clone $openQuery)->count();
                    $overdue = (clone $openQuery)
                        ->whereHas('order.dates', fn (Builder $query) => $query->where('finish_date', '<', now()))
                        ->count();
                    $dueToday = (clone $openQuery)
                        ->whereHas('order.dates', fn (Builder $query) => $query->whereDate('finish_date', $today->toDateString()))
                        ->count();
                    $completed30d = FactoryOrder::query()
                        ->where('operator_id', $operator->id)
                        ->whereNotNull('admin_confirmation_date')
                        ->where('admin_confirmation_date', '>=', now()->subDays(30))
                        ->count();

                    return [
                        'id' => $operator->id,
                        'name' => $operator->name,
                        'email' => $operator->email,
                        'role' => $operator->role ? [
                            'name' => $operator->role->name,
                            'value' => $operator->role->value,
                        ] : null,
                        'factory' => $operator->factory ? [
                            'id' => $operator->factory->id,
                            'name' => $operator->factory->name,
                            'value' => $operator->factory->value,
                        ] : null,
                        'active_orders' => $active,
                        'overdue_orders' => $overdue,
                        'due_today' => $dueToday,
                        'completed_30d' => $completed30d,
                        'workload' => $this->workloadLevel($active, $overdue),
                    ];
                })
                ->sortByDesc(fn (array $row) => ($row['overdue_orders'] * 100) + $row['active_orders'])
                ->values();

            $attentionOrders = Order::query()
                ->with([
                    'orderNumber',
                    'prefixCode',
                    'dates',
                    'factoryOrders.factory:id,name,value',
                    'factoryOrders.operator:id,name,factory_id',
                    'client.user:id,name,email',
                    'creator:id,name',
                ])
                ->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
                ->where(function (Builder $query) {
                    $query->whereHas('dates', fn (Builder $dateQuery) => $dateQuery->where('finish_date', '<', now()))
                        ->orWhereHas('factoryOrders', fn (Builder $factoryQuery) => $factoryQuery->whereNull('operator_id'))
                        ->orWhereHas('factoryOrders', fn (Builder $factoryQuery) => $factoryQuery
                            ->whereIn('status', self::DONE_FACTORY_STATUSES)
                            ->whereNull('admin_confirmation_date'));
                })
                ->latest('id')
                ->limit(12)
                ->get();

            $creatorIds = Order::query()
                ->whereNotNull('creator_id')
                ->distinct()
                ->pluck('creator_id');

            return response()->json([
                'summary' => $summary,
                'factories' => $factories,
                'operators' => $operators,
                'attention_orders' => $attentionOrders,
                'filters' => [
                    'factories' => Factory::query()
                        ->orderBy('name')
                        ->get(['id', 'name', 'value']),
                    'operators' => User::query()
                        ->whereNotNull('factory_id')
                        ->orderBy('name')
                        ->get(['id', 'name', 'factory_id']),
                    'factory_statuses' => FactoryOrderStatus::query()
                        ->where('is_active', true)
                        ->whereNotNull('value')
                        ->orderBy('sort_order')
                        ->get(['key', 'status_label', 'name', 'value', 'color', 'icon']),
                    'creators' => User::query()
                        ->whereIn('id', $creatorIds)
                        ->orderBy('name')
                        ->get(['id', 'name']),
                    'order_statuses' => Order::query()
                        ->whereNotNull('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status')
                        ->values(),
                ],
                'generated_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard failed', ['exception' => $e]);

            return response()->json([
                'message' => 'Unable to load admin dashboard.',
            ], 500);
        }
    }

    public function orders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'factory_id' => ['nullable', 'integer', 'exists:factories,id'],
            'operator_id' => ['nullable', 'integer', 'exists:users,id'],
            'creator_id' => ['nullable', 'integer', 'exists:users,id'],
            'order_status' => ['nullable', 'string', 'max:100'],
            'factory_status' => ['nullable', 'string', 'max:100'],
            'time_range' => ['nullable', 'in:overdue,today,tomorrow,this_week,next_7_days,no_deadline'],
            'assignment' => ['nullable', 'in:assigned,unassigned'],
            'confirmation' => ['nullable', 'in:waiting,confirmed'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'finish_from' => ['nullable', 'date'],
            'finish_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:newest,oldest,deadline_asc,deadline_desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        try {
            $query = Order::query()->with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory:id,name,value',
                'factoryOrders.operator:id,name,factory_id',
                'factoryOrders.files',
                'selectedFiles.pmpFile',
                'client.user:id,name,email',
                'user:id,name,email',
                'creator:id,name',
                'logs.user:id,name',
            ]);

            if (!empty($validated['search'])) {
                $search = trim($validated['search']);
                $query->where(function (Builder $searchQuery) use ($search) {
                    $searchQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('orderNumber', fn (Builder $q) => $q->where('number', 'like', "%{$search}%"))
                        ->orWhereHas('prefixCode', fn (Builder $q) => $q->where('code', 'like', "%{$search}%"))
                        ->orWhereHas('client.user', function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('creator', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
                });
            }

            if (!empty($validated['factory_id'])) {
                $factoryId = (int) $validated['factory_id'];
                $query->whereHas('factoryOrders', fn (Builder $q) => $q->where('factory_id', $factoryId));
            }

            if (!empty($validated['operator_id'])) {
                $operatorId = (int) $validated['operator_id'];
                $query->whereHas('factoryOrders', fn (Builder $q) => $q->where('operator_id', $operatorId));
            }

            if (!empty($validated['creator_id'])) {
                $query->where('creator_id', (int) $validated['creator_id']);
            }

            if (!empty($validated['order_status'])) {
                $query->where('status', $validated['order_status']);
            }

            if (!empty($validated['factory_status'])) {
                $status = $validated['factory_status'];
                $query->whereHas('factoryOrders', fn (Builder $q) => $q->where('status', $status));
            }

            if (($validated['assignment'] ?? null) === 'assigned') {
                $query->whereHas('factoryOrders', fn (Builder $q) => $q->whereNotNull('operator_id'));
            } elseif (($validated['assignment'] ?? null) === 'unassigned') {
                $query->whereHas('factoryOrders', fn (Builder $q) => $q->whereNull('operator_id'));
            }

            if (($validated['confirmation'] ?? null) === 'waiting') {
                $query->whereHas('factoryOrders', fn (Builder $q) => $q
                    ->whereIn('status', self::DONE_FACTORY_STATUSES)
                    ->whereNull('admin_confirmation_date'));
            } elseif (($validated['confirmation'] ?? null) === 'confirmed') {
                $query->whereHas('factoryOrders', fn (Builder $q) => $q->whereNotNull('admin_confirmation_date'));
            }

            $this->applyTimeRange($query, $validated['time_range'] ?? null);

            if (!empty($validated['created_from'])) {
                $query->whereDate('created_at', '>=', $validated['created_from']);
            }
            if (!empty($validated['created_to'])) {
                $query->whereDate('created_at', '<=', $validated['created_to']);
            }
            if (!empty($validated['finish_from'])) {
                $query->whereHas('dates', fn (Builder $q) => $q->whereDate('finish_date', '>=', $validated['finish_from']));
            }
            if (!empty($validated['finish_to'])) {
                $query->whereHas('dates', fn (Builder $q) => $q->whereDate('finish_date', '<=', $validated['finish_to']));
            }

            $sort = $validated['sort'] ?? 'newest';
            if ($sort === 'oldest') {
                $query->oldest('orders.id');
            } elseif ($sort === 'deadline_asc') {
                $query->orderByRaw('(SELECT finish_date FROM dates WHERE dates.order_id = orders.id LIMIT 1) IS NULL')
                    ->orderByRaw('(SELECT finish_date FROM dates WHERE dates.order_id = orders.id LIMIT 1) ASC');
            } elseif ($sort === 'deadline_desc') {
                $query->orderByRaw('(SELECT finish_date FROM dates WHERE dates.order_id = orders.id LIMIT 1) IS NULL')
                    ->orderByRaw('(SELECT finish_date FROM dates WHERE dates.order_id = orders.id LIMIT 1) DESC');
            } else {
                $query->latest('orders.id');
            }

            $orders = $query->paginate((int) ($validated['per_page'] ?? 30));

            return response()->json([
                'orders' => $orders->items(),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                    'last_page' => $orders->lastPage(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin order explorer failed', [
                'filters' => $request->except(['password', 'token']),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Unable to load filtered orders.',
            ], 500);
        }
    }

    private function openFactoryOrders(): Builder
    {
        return FactoryOrder::query()->where(function (Builder $query) {
            $query->whereNull('status')
                ->orWhereNotIn('status', array_merge(self::DONE_FACTORY_STATUSES, self::CANCELED_FACTORY_STATUSES))
                ->orWhere(function (Builder $doneQuery) {
                    $doneQuery->whereIn('status', self::DONE_FACTORY_STATUSES)
                        ->whereNull('admin_confirmation_date');
                });
        });
    }

    private function factoryHealth(int $active, int $overdue, int $unassigned, int $awaitingAdmin): string
    {
        if ($overdue >= 3 || $unassigned >= 5) {
            return 'critical';
        }
        if ($overdue > 0 || $unassigned >= 2 || $awaitingAdmin >= 3) {
            return 'attention';
        }
        if ($active === 0) {
            return 'idle';
        }

        return 'healthy';
    }

    private function workloadLevel(int $active, int $overdue): string
    {
        if ($overdue >= 2 || $active >= 8) {
            return 'overloaded';
        }
        if ($overdue >= 1 || $active >= 5) {
            return 'busy';
        }
        if ($active >= 2) {
            return 'normal';
        }
        if ($active === 1) {
            return 'light';
        }

        return 'idle';
    }

    private function applyTimeRange(Builder $query, ?string $range): void
    {
        if (!$range) {
            return;
        }

        $today = now()->startOfDay();
        $tomorrow = (clone $today)->addDay();

        if ($range === 'no_deadline') {
            $query->where(function (Builder $q) {
                $q->whereDoesntHave('dates')
                    ->orWhereHas('dates', fn (Builder $dateQuery) => $dateQuery->whereNull('finish_date'));
            });
            return;
        }

        if ($range === 'overdue') {
            $query->whereNotIn('status', self::CLOSED_ORDER_STATUSES)
                ->whereHas('dates', fn (Builder $q) => $q->where('finish_date', '<', now()));
            return;
        }

        if ($range === 'today') {
            $query->whereHas('dates', fn (Builder $q) => $q->whereDate('finish_date', $today->toDateString()));
            return;
        }

        if ($range === 'tomorrow') {
            $query->whereHas('dates', fn (Builder $q) => $q->whereDate('finish_date', $tomorrow->toDateString()));
            return;
        }

        $days = $range === 'this_week' ? 7 - (int) $today->dayOfWeekIso : 7;
        $end = (clone $today)->addDays(max($days, 1))->endOfDay();
        $query->whereHas('dates', fn (Builder $q) => $q
            ->where('finish_date', '>=', now())
            ->where('finish_date', '<=', $end));
    }

    public function show($id): JsonResponse
    {
        return response()->json(['message' => 'Admin details', 'data' => null]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Admin created successfully', 'data' => null], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        return response()->json(['message' => 'Admin updated successfully', 'data' => null]);
    }

    public function destroy($id): JsonResponse
    {
        return response()->json(null, 204);
    }
}
