<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retain legacy file-section rows and every file/task. Only their
        // production classification and invalid role metadata are corrected.
        if (Schema::hasColumn('factories', 'is_workshop')) {
            DB::table('factories')->whereIn(DB::raw('UPPER(TRIM(value))'), ['SW', 'INFO', 'PDF'])->update(['is_workshop' => false]);
            $this->preserveTaskAttachments();
            if (Schema::hasColumn('factory_orders', 'depends_on_id')) {
                DB::table('factory_orders')->whereIn('factory_id', DB::table('factories')->where('is_workshop', false)->select('id'))
                    ->select('id')->orderBy('id')->chunkById(500, function ($steps) {
                        DB::table('factory_orders')->whereIn('depends_on_id', $steps->pluck('id'))->update(['depends_on_id' => null]);
                    });
            }
        }
        $roles = DB::table('roles')->whereIn('name', ['admin', 'manager', 'engineer', 'authenticatedUser'])->pluck('id');
        foreach (['membership_assignments', 'company_memberships', 'users'] as $table) {
            if (Schema::hasColumns($table, ['role_id', 'factory_id'])) {
                DB::table($table)->whereIn('role_id', $roles)->whereNotNull('factory_id')->update(['factory_id' => null]);
            }
        }
    }

    private function preserveTaskAttachments(): void
    {
        if (!Schema::hasColumns('selected_files', ['order_id', 'pmp_file_id', 'company_id'])
            || !Schema::hasColumns('factory_order_files', ['factory_order_id', 'pmp_files_id', 'company_id'])) return;
        $hasRevision = Schema::hasColumn('factory_order_files', 'task_file_revision_id')
            && Schema::hasColumn('selected_files', 'task_file_revision_id');
        // Files previously stored only under a file section remain task
        // attachments. This does not grant access to any production workshop.
        DB::table('factory_order_files as attachment')
            ->join('factory_orders as step', 'step.id', '=', 'attachment.factory_order_id')
            ->join('factories as factory', 'factory.id', '=', 'step.factory_id')
            ->join('orders as task', 'task.id', '=', 'step.order_id')
            ->join('pmp_files as file', 'file.id', '=', 'attachment.pmp_files_id')
            ->where('factory.is_workshop', false)
            ->whereColumn('attachment.company_id', 'task.company_id')
            ->whereColumn('step.company_id', 'task.company_id')
            ->whereColumn('factory.company_id', 'task.company_id')
            ->whereColumn('file.company_id', 'task.company_id')
            ->select(['attachment.id', 'step.order_id', 'task.company_id', 'attachment.pmp_files_id', 'attachment.quantity'])
            ->when($hasRevision, fn ($query) => $query->addSelect('attachment.task_file_revision_id'))
            ->chunkById(500, function ($rows) use ($hasRevision) {
                foreach ($rows as $row) {
                    $data = ['company_id' => $row->company_id, 'order_id' => $row->order_id,
                        'pmp_file_id' => $row->pmp_files_id, 'quantity' => $row->quantity ?? 1,
                        'created_at' => now(), 'updated_at' => now()];
                    if ($hasRevision) $data['task_file_revision_id'] = $row->task_file_revision_id;
                    DB::table('selected_files')->insertOrIgnore($data);
                }
            }, 'attachment.id', 'id');
    }

    public function down(): void
    {
        // Invalid production assignments must not be reintroduced on rollback.
    }
};
