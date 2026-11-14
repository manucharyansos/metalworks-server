<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company's own positions, arranged as an org chart. Each position gives
     * its holders one of the system access levels (role) and, for production
     * work, a workshop; employees hold positions through their assignments.
     */
    public function up(): void
    {
        if (!Schema::hasTable('company_positions')) {
            Schema::create('company_positions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('company_positions')->nullOnDelete();
                $table->string('name', 150);
                $table->foreignId('role_id')->constrained('roles');
                $table->foreignId('factory_id')->nullable()->constrained('factories')->nullOnDelete();
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['company_id', 'parent_id']);
            });
        }

        if (!Schema::hasColumn('membership_assignments', 'position_id')) {
            Schema::table('membership_assignments', function (Blueprint $table): void {
                $table->foreignId('position_id')->nullable()->after('factory_id')
                    ->constrained('company_positions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('membership_assignments', 'position_id')) {
            Schema::table('membership_assignments', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('position_id');
            });
        }
        Schema::dropIfExists('company_positions');
    }
};
