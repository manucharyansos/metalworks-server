<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('membership_assignments')) {
            Schema::create('membership_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('membership_id')->constrained('company_memberships')->cascadeOnDelete();
                // Derived from the membership, never accepted from an API payload.
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('role_id')->constrained('roles');
                $table->foreignId('factory_id')->nullable()->constrained('factories');
                $table->timestamps();
                $table->unique(['membership_id', 'role_id', 'factory_id'], 'membership_assignments_role_workshop_unique');
            });
        }
        // Preserve every existing company, position, workshop and permission.
        // A retry never replaces assignments already edited by a manager.
        DB::table('company_memberships')->whereNotNull('role_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('membership_assignments')
                ->whereColumn('membership_assignments.membership_id', 'company_memberships.id'))
            ->orderBy('id')->chunkById(200, function ($memberships): void {
                foreach ($memberships as $membership) DB::table('membership_assignments')->insert([
                    'membership_id' => $membership->id, 'user_id' => $membership->user_id,
                    'role_id' => $membership->role_id, 'factory_id' => $membership->factory_id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        throw new RuntimeException('Multiple positions cannot be reduced automatically. Restore the pre-migration backup to roll back.');
    }
};
