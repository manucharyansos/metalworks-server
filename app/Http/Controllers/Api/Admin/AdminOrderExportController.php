<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminOrderExportController extends Controller
{
    private const CLOSED_ORDER_STATUSES = ['completed', 'canceled', 'cancelled'];
    private const DONE_FACTORY_STATUSES = ['finished', 'completed', 'done', 'confirmed'];

    public function __invoke(Request $request): StreamedResponse
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
        ]);

        $query = $this->filteredQuery($validated)
            ->with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory:id,name,value',
                'factoryOrders.operator:id,name,factory_id',
                'client.user:id,name,email',
                'user:id,name,email',
                'creator:id,name',
            ]);

        $filename = 'metalworks-orders-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM keeps Armenian text readable in Excel without manual import settings.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Order ID',
                'Order Number',
                'Prefix',
                'Name',
                'Order Status',
                'Customer',
                'Customer Email',
                'Creator',
                'Created At',
                'Finish Date',
                'Factories',
                'Operators',
                'Factory Statuses',
                'Admin Confirmations',
            ]);

            $query->orderBy('orders.id')->chunkById(500, function ($orders) use ($handle) {
                foreach ($orders as $order) {
                    $factoryOrders = $order->factoryOrders ?? collect();
                    $customerUser = $order->client?->user ?: $order->user;

                    fputcsv($handle, array_map([$this, 'safeCsvValue'], [
                        $order->id,
                        $order->orderNumber?->number,
                        $order->prefixCode?->code,
                        $order->name,
                        $order->status,
                        $customerUser?->name,
                        $customerUser?->email,
                        $order->creator?->name,
                        $order->getRawOriginal('created_at') ?: $order->created_at,
                        $order->dates?->finish_date,
                        $factoryOrders->pluck('factory.name')->filter()->implode(' | '),
                        $factoryOrders->map(fn ($row) => $row->operator?->name ?: 'unassigned')->implode(' | '),
                        $factoryOrders->pluck('status')->filter()->implode(' | '),
                        $factoryOrders->map(fn ($row) => $row->admin_confirmation_date ?: 'waiting')->implode(' | '),
                    ]));
                }

                fflush($handle);
            }, 'orders.id', 'id');

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = Order::query();

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
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
                    ->orWhereHas('user', function (Builder $q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('creator', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        if (!empty($filters['factory_id'])) {
            $factoryId = (int) $filters['factory_id'];
            $query->whereHas('factoryOrders', fn (Builder $q) => $q->where('factory_id', $factoryId));
        }

        if (!empty($filters['operator_id'])) {
            $operatorId = (int) $filters['operator_id'];
            $query->whereHas('factoryOrders', fn (Builder $q) => $q->where('operator_id', $operatorId));
        }

        if (!empty($filters['creator_id'])) {
            $query->where('creator_id', (int) $filters['creator_id']);
        }

        if (!empty($filters['order_status'])) {
            $query->where('status', $filters['order_status']);
        }

        if (!empty($filters['factory_status'])) {
            $status = $filters['factory_status'];
            $query->whereHas('factoryOrders', fn (Builder $q) => $q->where('status', $status));
        }

        if (($filters['assignment'] ?? null) === 'assigned') {
            $query->whereHas('factoryOrders', fn (Builder $q) => $q->whereNotNull('operator_id'));
        } elseif (($filters['assignment'] ?? null) === 'unassigned') {
            $query->whereHas('factoryOrders', fn (Builder $q) => $q->whereNull('operator_id'));
        }

        if (($filters['confirmation'] ?? null) === 'waiting') {
            $query->whereHas('factoryOrders', fn (Builder $q) => $q
                ->whereIn('status', self::DONE_FACTORY_STATUSES)
                ->whereNull('admin_confirmation_date'));
        } elseif (($filters['confirmation'] ?? null) === 'confirmed') {
            $query->whereHas('factoryOrders', fn (Builder $q) => $q->whereNotNull('admin_confirmation_date'));
        }

        $this->applyTimeRange($query, $filters['time_range'] ?? null);

        if (!empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }
        if (!empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }
        if (!empty($filters['finish_from'])) {
            $query->whereHas('dates', fn (Builder $q) => $q->whereDate('finish_date', '>=', $filters['finish_from']));
        }
        if (!empty($filters['finish_to'])) {
            $query->whereHas('dates', fn (Builder $q) => $q->whereDate('finish_date', '<=', $filters['finish_to']));
        }

        return $query;
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
            $query->whereHas('dates', fn (Builder $q) => $q
                ->where('finish_date', '>=', $today)
                ->where('finish_date', '<', $tomorrow));
            return;
        }

        if ($range === 'tomorrow') {
            $dayAfterTomorrow = (clone $tomorrow)->addDay();
            $query->whereHas('dates', fn (Builder $q) => $q
                ->where('finish_date', '>=', $tomorrow)
                ->where('finish_date', '<', $dayAfterTomorrow));
            return;
        }

        $days = $range === 'this_week' ? 7 - (int) $today->dayOfWeekIso : 7;
        $end = (clone $today)->addDays(max($days, 1))->endOfDay();
        $query->whereHas('dates', fn (Builder $q) => $q
            ->where('finish_date', '>=', now())
            ->where('finish_date', '<=', $end));
    }

    private function safeCsvValue(mixed $value): string
    {
        $string = trim((string) ($value ?? ''));

        // Prevent spreadsheet formula injection when a CSV is opened in Excel/Sheets.
        if ($string !== '' && preg_match('/^[=+\-@]/u', $string)) {
            return "'" . $string;
        }

        return $string;
    }
}
