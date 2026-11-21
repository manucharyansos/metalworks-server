<?php

namespace App\Support;

use App\Models\{Order, Pmp, PmpFiles, RemoteNumber};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Schema, Storage};
use Illuminate\Validation\ValidationException;

/**
 * Removes a project subgroup (or a whole group) together with its files, but
 * only while no task uses it: tasks keep their files and history, so a project
 * that is already in production can be renamed, not deleted.
 */
final class ProjectRemoval
{
    private const SHOWN_TASKS = 5;

    public static function preview(RemoteNumber $remote): array
    {
        $files = PmpFiles::where('remote_number_id', $remote->id)->pluck('id');
        $taskIds = self::taskIds([$remote->id], $files);

        return [
            'files' => $files->count(),
            'tasks' => self::taskSummaries($taskIds),
            'tasks_total' => $taskIds->count(),
            'removes_group' => self::groupEmptiesWithout($remote),
            'can_delete' => $taskIds->isEmpty(),
        ];
    }

    /** @return array{group_deleted: bool} */
    public static function deleteSubgroup(RemoteNumber $remote): array
    {
        $paths = [];
        $directories = [];
        $groupDeleted = DB::transaction(function () use ($remote, &$paths, &$directories) {
            $pmp = Pmp::whereKey($remote->pmp_id)->lockForUpdate()->firstOrFail();
            $remote = RemoteNumber::whereKey($remote->id)->where('pmp_id', $pmp->id)->lockForUpdate()->firstOrFail();
            $files = PmpFiles::where('remote_number_id', $remote->id)->lockForUpdate()->get();
            self::assertUnused([$remote->id], $files->pluck('id'), self::label($pmp, $remote));

            $paths = self::deleteFiles($files);
            $directories[] = self::directory($pmp, $remote);
            $remote->delete();

            // A group with nothing left in it would only clutter the project list.
            $empty = !RemoteNumber::where('pmp_id', $pmp->id)->exists()
                && !PmpFiles::where('pmp_id', $pmp->id)->exists();
            if ($empty) $pmp->delete();

            return $empty;
        }, 3);
        self::removeStored($paths, $directories);

        return ['group_deleted' => $groupDeleted];
    }

    public static function deleteGroup(Pmp $pmp): void
    {
        $paths = [];
        $directories = [];
        DB::transaction(function () use ($pmp, &$paths, &$directories) {
            $pmp = Pmp::whereKey($pmp->id)->lockForUpdate()->firstOrFail();
            $remotes = RemoteNumber::where('pmp_id', $pmp->id)->lockForUpdate()->get();
            $files = PmpFiles::where('pmp_id', $pmp->id)->lockForUpdate()->get();
            self::assertUnused($remotes->pluck('id')->all(), $files->pluck('id'), trim($pmp->group.' — '.$pmp->group_name));

            $paths = self::deleteFiles($files);
            foreach ($remotes as $remote) {
                $directories[] = self::directory($pmp, $remote);
                $remote->delete();
            }
            $pmp->delete();
        }, 3);
        self::removeStored($paths, $directories);
    }

    /**
     * Every task that points at the subgroups or at one of their files, by any
     * of the links tasks use: the task itself, its file list, a workshop step's
     * files or a task file version.
     */
    private static function taskIds(array $remoteIds, Collection $fileIds): Collection
    {
        $ids = DB::table('orders')->whereIn('remote_number_id', $remoteIds)->pluck('id');
        if ($fileIds->isNotEmpty()) {
            $files = $fileIds->all();
            $ids = $ids->merge(DB::table('selected_files')->whereIn('pmp_file_id', $files)->pluck('order_id'))
                ->merge(DB::table('factory_order_files')->join('factory_orders', 'factory_orders.id', '=', 'factory_order_files.factory_order_id')
                    ->whereIn('factory_order_files.pmp_files_id', $files)->pluck('factory_orders.order_id'));
            if (Schema::hasTable('task_file_revisions')) {
                $ids = $ids->merge(DB::table('task_file_revisions')->whereIn('pmp_file_id', $files)->pluck('order_id'));
            }
            if (Schema::hasTable('factory_orders_files')) {
                $ids = $ids->merge(DB::table('factory_orders_files')->join('factory_orders', 'factory_orders.id', '=', 'factory_orders_files.factory_order_id')
                    ->whereIn('factory_orders_files.pmp_file_id', $files)->pluck('factory_orders.order_id'));
            }
        }

        return $ids->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
    }

    private static function assertUnused(array $remoteIds, Collection $fileIds, string $label): void
    {
        $taskIds = self::taskIds($remoteIds, $fileIds);
        if ($taskIds->isEmpty()) return;

        $numbers = collect(self::taskSummaries($taskIds))->map(fn (array $task) => $task['number'] ?: $task['name'])->filter()->implode(', ');
        $more = $taskIds->count() > self::SHOWN_TASKS ? ', …' : '';
        throw ValidationException::withMessages(['project' => [sprintf(
            '«%s»-ը օգտագործվում է %d առաջադրանքում%s։ Առաջադրանքների ֆայլերն ու պատմությունը պահպանելու համար այն ջնջել հնարավոր չէ։ Կարող եք վերանվանել այն կամ նախ ջնջել այդ առաջադրանքները։',
            $label, $taskIds->count(), $numbers !== '' ? ' ('.$numbers.$more.')' : ''
        )]]);
    }

    private static function taskSummaries(Collection $taskIds): array
    {
        if ($taskIds->isEmpty()) return [];

        return Order::with('orderNumber')->whereIn('id', $taskIds->take(self::SHOWN_TASKS)->all())->orderBy('id')->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->orderNumber?->number,
                'name' => $order->name,
                'status' => $order->status,
            ])->values()->all();
    }

    private static function groupEmptiesWithout(RemoteNumber $remote): bool
    {
        return !RemoteNumber::where('pmp_id', $remote->pmp_id)->whereKeyNot($remote->id)->exists()
            && !PmpFiles::where('pmp_id', $remote->pmp_id)->where('remote_number_id', '!=', $remote->id)->exists();
    }

    /** Deletes the records one by one so the activity log keeps who removed what. */
    private static function deleteFiles(Collection $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            if ($file->path) $paths[] = $file->path;
            $file->delete();
        }

        return $paths;
    }

    private static function directory(Pmp $pmp, RemoteNumber $remote): string
    {
        return 'companies/'.$pmp->company_id."/PMP_{$pmp->group}.{$remote->remote_number}";
    }

    // Runs after the commit: a rolled back deletion must not lose the stored files.
    private static function removeStored(array $paths, array $directories): void
    {
        foreach (['private', 'public'] as $disk) {
            $storage = Storage::disk($disk);
            foreach ($paths as $path) {
                if ($storage->exists($path)) $storage->delete($path);
            }
        }
        $storage = Storage::disk('private');
        foreach ($directories as $directory) {
            if ($storage->exists($directory) && !$storage->allFiles($directory)) $storage->deleteDirectory($directory);
        }
    }

    private static function label(Pmp $pmp, RemoteNumber $remote): string
    {
        return trim($pmp->group.'.'.$remote->remote_number.' — '.$remote->remote_number_name);
    }
}
