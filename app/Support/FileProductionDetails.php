<?php

namespace App\Support;

use App\Models\{Factory, FactoryOrder, Order, PmpFiles, TaskFileRevision, User};

final class FileProductionDetails
{
    public static function forFile(User $user, PmpFiles $file, array $context): array
    {
        abort_unless(TaskAccess::canDownloadPmp($user, $file), 403);
        $step = null;
        $order = null;
        $revision = null;
        if (!empty($context['factory_order_id'])) {
            $step = FactoryOrder::whereHas('files', fn ($q) => $q->whereKey($file->id))->findOrFail($context['factory_order_id']);
            $order = $step->order;
        }
        if (!empty($context['order_id'])) {
            abort_if($order && (int) $order->id !== (int) $context['order_id'], 404);
            $order ??= Order::findOrFail($context['order_id']);
        }
        if (!empty($context['revision_id'])) {
            $revision = TaskFileRevision::where('pmp_file_id', $file->id)->findOrFail($context['revision_id']);
            abort_if($order && (int) $order->id !== (int) $revision->order_id, 404);
            $order ??= $revision->order;
        }

        $pivot = null;
        $selected = null;
        if ($order) {
            TaskFileVersions::authorizeRead($user, $order);
            $steps = $order->factoryOrders()->forWorkshops()
                ->whereHas('files', fn ($q) => $q->whereKey($file->id))
                ->with(['factory', 'files' => fn ($q) => $q->whereKey($file->id)])
                ->orderBy('route_position')->orderBy('id')->get();
            $operator = $user->factory_id && !in_array($user->role?->name, ['admin', 'manager'], true);
            $visible = $steps->filter(fn ($s) => !$operator || ((int) $s->factory_id === (int) $user->factory_id
                && (!$s->operator_id || (int) $s->operator_id === (int) $user->id)));
            if ($step) {
                abort_unless($visible->contains('id', $step->id), 403);
                $step = $visible->firstWhere('id', $step->id);
            }
            else $step = $visible->first();
            $selected = $order->selectedFiles()->where('pmp_file_id', $file->id)->first();
            abort_unless($step || (!$operator && $selected), 404);
            $pivot = $step?->files->firstWhere('id', $file->id)?->pivot;
            $workshops = $steps->map(fn ($s) => ['id' => (int) $s->factory_id, 'name' => $s->factory->name,
                'value' => $s->factory->value, 'is_active' => (bool) $s->factory->is_active,
                'current' => $step && (int) $s->id === (int) $step->id,
                'functions' => self::functions($s->files->firstWhere('id', $file->id)?->pivot?->production_functions)])->values()->all();
        } else {
            $ids = $file->is_reference_file ? [] : $file->routing_factory_ids;
            $catalog = Factory::workshops()->where('company_id', $file->company_id)->whereIn('id', $ids)->get()->keyBy('id');
            $chosen = WorkshopFunctions::forFiles([$file])[(int) $file->id] ?? [];
            $workshops = collect($ids)->filter(fn ($id) => $catalog->has($id))->map(fn ($id) => [
                'id' => (int) $id, 'name' => $catalog[$id]->name, 'value' => $catalog[$id]->value,
                'is_active' => (bool) $catalog[$id]->is_active, 'current' => false,
                'functions' => self::functions($chosen[(int) $id] ?? []),
            ])->values()->all();
        }

        $attribute = fn ($key) => $pivot && array_key_exists($key, $pivot->getAttributes()) ? $pivot->getAttribute($key) : $file->getAttribute($key);
        return ['id' => $file->id, 'original_name' => $revision?->original_name ?? $file->original_name,
            'category_name' => $file->category?->name, 'is_reference_file' => $file->is_reference_file,
            'source' => $order ? 'task' : 'project', 'order_id' => $order?->id,
            'factory_order_id' => $step?->id, 'revision_id' => $revision?->id,
            'quantity' => $pivot ? $attribute('quantity') : ($selected?->quantity ?? $file->quantity),
            'material_type' => $attribute('material_type'), 'thickness' => $attribute('thickness'),
            'dimensions' => $attribute('dimensions'), 'inspection_required' => (bool) $attribute('inspection_required'),
            'workshops' => $workshops];
    }

    /** [['id' => …, 'name' => …], …] from a stored list; anything else is dropped. */
    private static function functions($rows): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($row) => is_array($row) && isset($row['name']) && $row['name'] !== '')
            ->map(fn ($row) => ['id' => (int) ($row['id'] ?? 0), 'name' => (string) $row['name']])->values()->all();
    }
}
