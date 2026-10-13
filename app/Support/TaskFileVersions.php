<?php

namespace App\Support;

use App\Models\{Order, OrderLog, PmpFiles, TaskFileRevision, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TaskFileVersions
{
    public static function authorizeRead(User $user, Order $order): void
    {
        abort_unless($user->role?->name !== 'authenticatedUser' && TaskAccess::canView($user, $order)
            && ($user->role?->name === 'admin' || $user->hasPermission($user->factory_id ? 'factory.download' : 'pmp_files.view')), 403);
    }

    public static function files(User $user, Order $order)
    {
        self::authorizeRead($user, $order);
        $steps = $order->factoryOrders()->with('files')->when($user->factory_id && !in_array($user->role?->name, ['admin', 'manager'], true), fn ($q) => TaskAccess::operatorSteps($q, $user))->get();
        $files = $steps->flatMap(fn ($step) => $step->files)->unique('id');
        $selected = $order->selectedFiles()->get()->keyBy('pmp_file_id');
        $revisions = TaskFileRevision::with('user:id,name')->where('order_id', $order->id)->whereIn('pmp_file_id', $files->pluck('id'))->orderByDesc('version')->get()->groupBy('pmp_file_id');
        return $files->map(function ($file) use ($selected, $revisions, $steps, $user) {
            // Operators see the exact version attached to their own workshop,
            // including an old version retained on an already finished job.
            $operatorView = $user->factory_id && !in_array($user->role?->name, ['admin', 'manager'], true);
            $current = $operatorView ? $file->pivot?->task_file_revision_id : $selected->get($file->id)?->task_file_revision_id;
            return ['id' => $file->id, 'original_name' => $file->original_name, 'path' => $file->path,
                'task_file_revision_id' => $current,
                'can_update' => !$operatorView && $steps->contains(fn ($step) => self::editable($step) && $step->files->contains('id', $file->id)),
                'versions' => $revisions->get($file->id, collect())->values()];
        })->values();
    }

    public static function editable($step): bool
    {
        return !$step->isWorkCompleted() && !$step->awaiting_engineer_confirmation
            && !in_array($step->status, ['canceled', 'cancelled'], true);
    }

    public static function update(User $actor, Order $order, PmpFiles $file, array $data, ?UploadedFile $upload = null): array
    {
        $stored = [];
        try {
            return DB::transaction(function () use ($actor, $order, $file, $data, $upload, &$stored) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                TaskRouting::authorize($actor, $order);
                self::authorizeRead($actor, $order);
                abort_if(in_array($order->status, ['canceled', 'cancelled'], true), 422, 'Չեղարկված առաջադրանքի ֆայլերը փոխել հնարավոր չէ։');
                $steps = $order->factoryOrders()->orderBy('id')->lockForUpdate()->get()->load('files');
                $related = $steps->filter(fn ($step) => $step->files->contains('id', $file->id));
                abort_unless($related->isNotEmpty(), 404);
                $active = $related->filter(fn ($step) => self::editable($step));
                abort_unless($active->isNotEmpty(), 422, 'Այս ֆայլով բոլոր աշխատանքներն արդեն ավարտված են կամ ներկայացված են հաստատման։');
                $selected = $order->selectedFiles()->where('pmp_file_id', $file->id)->firstOrFail();
                $previousId = $selected->task_file_revision_id;
                abort_unless((int) $previousId === (int) ($data['previous_revision_id'] ?? null), 409, 'Ֆայլի գործող տարբերակն արդեն փոխվել է։ Թարմացրեք ցանկը և կրկին փորձեք։');
                $extension = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
                if ($upload) {
                    if (!in_array($extension, ['dxf', 'pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'step', 'stp', 'sldprt', 'sldasm', 'igs', 'iges', 'dwg', 'dwt', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'zip'], true)
                        || strtolower($upload->getClientOriginalExtension()) !== $extension) {
                        throw ValidationException::withMessages(['file' => ['Նոր տարբերակը պետք է ունենա նույն թույլատրելի ֆայլի տեսակը։']]);
                    }
                    // Snapshot the original too: later PMP-library changes must
                    // not silently change this task's old versions or approvals.
                    if (!TaskFileRevision::where('order_id', $order->id)->where('pmp_file_id', $file->id)->exists()) {
                        abort_unless(Storage::disk('private')->exists($file->path), 422, 'Նախորդ ֆայլը չի գտնվել։');
                        $path = 'companies/'.$order->company_id.'/task-files/'.$order->id.'/'.Str::uuid().'.'.$extension;
                        $stored[] = $path;
                        if (!Storage::disk('private')->copy($file->path, $path)) throw new \RuntimeException('Could not archive original task file');
                        $original = TaskFileRevision::create(['order_id' => $order->id, 'pmp_file_id' => $file->id, 'user_id' => $actor->id,
                            'version' => 1, 'file_path' => $path, 'original_name' => $file->original_name,
                            'file_size' => Storage::disk('private')->size($path), 'mime_type' => Storage::disk('private')->mimeType($path), 'note' => 'Սկզբնական տարբերակ']);
                        foreach ($related as $step) if (!$step->files->firstWhere('id', $file->id)->pivot->task_file_revision_id) {
                            $step->files()->updateExistingPivot($file->id, ['task_file_revision_id' => $original->id]);
                        }
                    }
                    $path = $upload->storeAs('companies/'.$order->company_id.'/task-files/'.$order->id, Str::uuid().'.'.$extension, 'private');
                    $stored[] = $path;
                    $revision = TaskFileRevision::create(['order_id' => $order->id, 'pmp_file_id' => $file->id, 'user_id' => $actor->id,
                        'version' => (int) TaskFileRevision::where('order_id', $order->id)->where('pmp_file_id', $file->id)->max('version') + 1,
                        'file_path' => $path, 'original_name' => basename(str_replace('\\', '/', $upload->getClientOriginalName())),
                        'file_size' => $upload->getSize(), 'mime_type' => $upload->getMimeType(), 'note' => $data['note'] ?? null]);
                } else {
                    $revision = TaskFileRevision::where('order_id', $order->id)->where('pmp_file_id', $file->id)->whereKey($data['revision_id'])->firstOrFail();
                    if ((int) $revision->id === (int) $previousId) return [$order, $revision, []];
                }
                $selected->update(['task_file_revision_id' => $revision->id]);
                foreach ($active as $step) $step->files()->updateExistingPivot($file->id, ['task_file_revision_id' => $revision->id]);
                OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id, 'action' => 'order.file_version_changed',
                    'message' => sprintf('Ֆայլ «%s»․ գործող տարբերակը՝ %d։', $file->original_name, $revision->version),
                    'meta' => ['pmp_file_id' => $file->id, 'from_revision_id' => $previousId, 'to_revision_id' => $revision->id,
                        'version' => $revision->version, 'note' => $revision->note, 'factory_order_ids' => $active->pluck('id')->all()]]);
                return [$order, $revision, $active->all()];
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) Storage::disk('private')->delete($path);
            throw $e;
        }
    }
}
