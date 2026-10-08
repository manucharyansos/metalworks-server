<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resume safely after an interrupted MySQL DDL operation.
        if (!Schema::hasColumn('registration_requests', 'locale')) {
            Schema::table('registration_requests', fn (Blueprint $table) => $table->string('locale', 2)->default('hy'));
        }
        if (!Schema::hasColumn('registration_requests', 'notification_status')) {
            Schema::table('registration_requests', fn (Blueprint $table) => $table->string('notification_status', 20)->nullable());
        }
        if (!Schema::hasColumn('registration_requests', 'notification_sent_at')) {
            Schema::table('registration_requests', fn (Blueprint $table) => $table->timestamp('notification_sent_at')->nullable());
        }
        $new = 'registration_requests_company_email_type_unique';
        if (!Schema::hasIndex('registration_requests', $new)) {
            Schema::table('registration_requests', fn (Blueprint $table) => $table->unique(['company_id', 'email', 'type'], $new));
        }
        $old = 'registration_requests_company_id_email_unique';
        if (Schema::hasIndex('registration_requests', $old)) {
            Schema::table('registration_requests', fn (Blueprint $table) => $table->dropUnique($old));
        }
        // Older approvals become eligible for an explicit manager retry; this
        // migration sends no messages and leaves every account unchanged.
        DB::table('registration_requests')->where('status', 'approved')->whereNull('notification_status')
            ->update(['notification_status' => 'pending']);
    }

    public function down(): void
    {
        throw new RuntimeException('Registration types cannot be merged automatically. Restore the pre-migration backup to roll back.');
    }
};
