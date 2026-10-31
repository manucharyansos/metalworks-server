<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->boolean('access_enabled')->default(true));
        // Preserve revoked legacy customer links; staff membership state is independent.
        DB::table('clients')->whereExists(function ($q) {
            $q->selectRaw('1')->from('company_memberships as cm')->join('roles as r', 'r.id', '=', 'cm.role_id')
                ->whereColumn('cm.company_id', 'clients.company_id')->whereColumn('cm.user_id', 'clients.user_id')
                ->where('r.name', 'authenticatedUser')->where('cm.is_active', false);
        })->update(['access_enabled' => false]);
        DB::table('company_memberships as cm')->join('roles as r', 'r.id', '=', 'cm.role_id')->join('users as u', 'u.id', '=', 'cm.user_id')
            ->where('r.name', 'authenticatedUser')->select('cm.company_id', 'cm.user_id', 'cm.is_active', 'u.name', 'u.last_name')->orderBy('cm.id')->get()->each(function ($row) {
                if (!DB::table('clients')->where('company_id', $row->company_id)->where('user_id', $row->user_id)->exists()) {
                    DB::table('clients')->insert(['company_id' => $row->company_id, 'user_id' => $row->user_id, 'name' => $row->name, 'last_name' => $row->last_name, 'phone' => '', 'type' => 'physPerson', 'access_enabled' => $row->is_active, 'created_at' => now(), 'updated_at' => now()]);
                }
            });
    }
    public function down(): void { Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('access_enabled')); }
};
