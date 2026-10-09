<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate, guarded additions also make an interrupted MySQL DDL run retryable.
        $columns = [
            'orders' => [
                'completed_at' => fn (Blueprint $t) => $t->timestamp('completed_at')->nullable(),
                'confirmation_required' => fn (Blueprint $t) => $t->boolean('confirmation_required')->default(false),
                'confirmation_method' => fn (Blueprint $t) => $t->string('confirmation_method', 20)->nullable(),
                'reference_file_visibility' => fn (Blueprint $t) => $t->json('reference_file_visibility')->nullable(),
            ],
            'factories' => [
                'is_reference' => fn (Blueprint $t) => $t->boolean('is_reference')->default(false),
            ],
            'factory_orders' => [
                'confirmation_required' => fn (Blueprint $t) => $t->boolean('confirmation_required')->default(false),
                'confirmation_method' => fn (Blueprint $t) => $t->string('confirmation_method', 20)->nullable(),
                'evidence_text' => fn (Blueprint $t) => $t->text('evidence_text')->nullable(),
                'evidence_photo_path' => fn (Blueprint $t) => $t->string('evidence_photo_path')->nullable(),
                'engineer_confirmation_at' => fn (Blueprint $t) => $t->timestamp('engineer_confirmation_at')->nullable(),
                'engineer_confirmation_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('engineer_confirmation_user_id')->nullable(),
                'completed_at' => fn (Blueprint $t) => $t->timestamp('completed_at')->nullable(),
            ],
        ];
        foreach ($columns as $table => $definitions) {
            foreach ($definitions as $name => $add) {
                if (!Schema::hasColumn($table, $name)) Schema::table($table, $add);
            }
        }
        DB::table('factories')->whereIn('value', ['INFO', 'PDF'])->update(['is_reference' => true]);
        // Preserve old confirmations. Old finished work no longer needs admin approval.
        DB::table('factory_orders')->whereNull('completed_at')->where('confirmation_required', false)
            ->where(function ($q) {
                $q->whereIn('status', ['finished', 'completed', 'done'])
                    ->orWhere(fn ($s) => $s->where('status', 'confirmed')->whereNotNull('admin_confirmation_date'));
            })->update(['completed_at' => DB::raw('COALESCE(admin_confirmation_date, operator_finish_date, finish_date, updated_at)'), 'status' => 'finished']);
        // INFO/PDF rows in older tasks are retained for history, but are not production work.
        DB::table('factory_orders')->where('confirmation_required', false)->whereNull('completed_at')
            ->whereIn('factory_id', DB::table('factories')->where('is_reference', true)->select('id'))
            ->update(['completed_at' => DB::raw('updated_at'), 'status' => 'finished']);
        DB::table('orders')->whereNotIn('status', ['completed', 'canceled', 'cancelled'])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('factory_orders')->whereColumn('factory_orders.order_id', 'orders.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('factory_orders')->whereColumn('factory_orders.order_id', 'orders.id')
                ->whereNull('completed_at')->whereNotIn('status', ['canceled', 'cancelled']))
            ->update(['status' => 'completed', 'completed_at' => DB::raw('COALESCE(completed_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['confirmation_required', 'confirmation_method', 'reference_file_visibility', 'completed_at']));
        Schema::table('factories', fn (Blueprint $t) => $t->dropColumn('is_reference'));
        Schema::table('factory_orders', fn (Blueprint $t) => $t->dropColumn(['confirmation_required', 'confirmation_method', 'evidence_text', 'evidence_photo_path', 'engineer_confirmation_at', 'engineer_confirmation_user_id', 'completed_at']));
    }
};
