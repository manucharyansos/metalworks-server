<?php

namespace App\Http\Controllers\Api\Factories\Statusess;

use App\Http\Controllers\Controller;
use App\Models\FactoryOrderStatus;
use Illuminate\Http\Request;

class FactoryOrderStatusController extends Controller
{
    public function actions()
    {
        return FactoryOrderStatus::query()
            ->whereNotNull('value')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($status) {
                return [
                    'id'     => $status->key,
                    'label'  => $status->name,
                    'value'  => $status->value,
                    'icon'   => $status->icon,
                    'color'  => $status->color,
                ];
            });
    }

    public function filters()
    {
        $statuses = FactoryOrderStatus::where('is_active', true)
            ->orderBy('sort_order')
            ->get([
                'key as id',
                'status_label as label',
                'value',
                'icon',
                'color',
            ]);

        return response()->json($statuses);
    }
}
