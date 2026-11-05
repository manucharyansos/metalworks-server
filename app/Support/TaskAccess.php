<?php

namespace App\Support;

use App\Models\{FactoryOrder, Order, PmpFiles, User};

final class TaskAccess
{
    public static function operatorSteps($query, User $user)
    {
        return $query->forWorkshops()->where(function ($q) use ($user) {
            $q->where(fn ($own) => $own->where('factory_id', $user->factory_id)->where(fn ($s) => $s->whereNull('operator_id')->orWhere('operator_id', $user->id)))
                ->orWhere(fn ($assigned) => $assigned->where('task_scoped_operator', true)->where('operator_id', $user->id))
                ->orWhereHas('helpers', fn ($helpers) => $helpers->where('users.id', $user->id));
        });
    }

    public static function canOperate(User $user, FactoryOrder $step): bool {
        return (!$step->operator_id && (int) $step->factory_id === (int) $user->factory_id)
            || ((int) $step->operator_id === (int) $user->id && ($step->task_scoped_operator || (int) $step->factory_id === (int) $user->factory_id));
    }

    public static function canView(User $user, Order $order): bool
    {
        if (in_array($user->role?->name, ['admin', 'manager'], true)) return true;
        if ($user->role?->name === 'engineer') return (int) $order->creator_id === (int) $user->id;
        if ($user->role?->name === 'authenticatedUser') return (int) $order->user_id === (int) $user->id;
        return $user->factory_id && $user->hasPermission('factory.view')
            && self::operatorSteps($order->factoryOrders(), $user)->exists();
    }

    public static function canDownloadPmp(User $user, PmpFiles $file): bool
    {
        if ($user->role?->name === 'admin' || (!$user->factory_id && in_array($user->role?->name, ['engineer', 'manager'], true) && $user->hasPermission('pmp_files.view'))) return true;
        if (!$user->factory_id || !$user->hasPermission('factory.download')) return false;
        // A file is available only through a task step in the selected workshop.
        // Routing the same drawing to another workshop explicitly adds that link.
        return self::operatorSteps(FactoryOrder::query(), $user)->whereHas('files', fn ($q) => $q->whereKey($file->id))->exists();
    }

    public static function restrictOperatorRelations(Order $order, User $user): Order
    {
        if (!$user->factory_id || in_array($user->role?->name, ['admin', 'manager'], true)) return $order;
        $allowed = self::operatorSteps($order->factoryOrders(), $user)->pluck('factory_orders.id');
        $steps = $order->factoryOrders->whereIn('id', $allowed)->values();
        $order->setRelation('factoryOrders', $steps);
        $order->unsetRelation('factories')->unsetRelation('selectedFiles')->unsetRelation('logs');
        $order->makeHidden('reference_file_visibility');
        return $order;
    }
}
