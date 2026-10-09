<?php

namespace App\Support;

use App\Models\{FactoryOrder, Order, PmpFiles, User};

final class TaskAccess
{
    public static function operatorSteps($query, User $user)
    {
        return $query->where('factory_id', $user->factory_id)->where(fn ($q) => $q->whereNull('operator_id')->orWhere('operator_id', $user->id));
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
        // Reference files are linked only to explicitly selected steps. Normal
        // production files must additionally belong to the selected workshop.
        if (!$file->factory?->is_reference && (int) $file->factory_id !== (int) $user->factory_id) return false;
        return self::operatorSteps(FactoryOrder::query(), $user)->whereHas('files', fn ($q) => $q->whereKey($file->id))->exists();
    }

    public static function restrictOperatorRelations(Order $order, User $user): Order
    {
        if (!$user->factory_id || in_array($user->role?->name, ['admin', 'manager'], true)) return $order;
        $steps = $order->factoryOrders->filter(fn ($s) => (int) $s->factory_id === (int) $user->factory_id && (!$s->operator_id || (int) $s->operator_id === (int) $user->id))->values();
        $order->setRelation('factoryOrders', $steps);
        $order->unsetRelation('factories')->unsetRelation('selectedFiles')->unsetRelation('logs');
        $order->makeHidden('reference_file_visibility');
        return $order;
    }
}
