<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An operator cannot reject a job or move its deadline on their own.
        // The request waits here until the engineer who created the task decides.
        if (!Schema::hasTable('task_change_requests')) {
            Schema::create('task_change_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('factory_order_id');
                $table->unsignedBigInteger('requested_by');
                $table->string('type', 20); // reject | deadline
                $table->string('reason', 1000)->nullable(); // reject reason code
                $table->string('operator_note', 1000)->nullable(); // optional free-text explanation
                $table->date('proposed_date')->nullable();
                $table->dateTime('previous_deadline')->nullable();
                $table->string('status', 20)->default('pending'); // pending | approved | declined | withdrawn
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('resolution', 20)->nullable(); // canceled | reassigned | deadline_changed
                $table->string('decision_note', 1000)->nullable();
                $table->timestamps();
                $table->index(['factory_order_id', 'status']);
            });
        }

        // In-app notifications. One row per recipient, scoped to a company.
        if (!Schema::hasTable('task_notifications')) {
            Schema::create('task_notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('type', 60);
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('factory_order_id')->nullable()->index();
                $table->string('message', 1000);
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'user_id', 'read_at']);
            });
        }

        // Filled when the engineer sends a finished job back for rework.
        foreach (['return_note' => fn (Blueprint $t) => $t->text('return_note')->nullable(),
                  'returned_at' => fn (Blueprint $t) => $t->timestamp('returned_at')->nullable()] as $column => $add) {
            if (!Schema::hasColumn('factory_orders', $column)) Schema::table('factory_orders', $add);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_notifications');
        Schema::dropIfExists('task_change_requests');
        foreach (['return_note', 'returned_at'] as $column) {
            if (Schema::hasColumn('factory_orders', $column)) Schema::table('factory_orders', fn (Blueprint $t) => $t->dropColumn($column));
        }
    }
};
