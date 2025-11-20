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
                'id',
                'key as id',
                'name as label',
                'value',
                'icon',
                'color'
            ]);

        return response()->json($statuses);
    }
}
