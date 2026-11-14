<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A position may leave its holders' access as it is: an assistant or a
     * worker sits under an engineer in the chart without getting the
     * engineer's access.
     */
    public function up(): void
    {
        Schema::table('company_positions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Positions without an access level cannot be given one automatically.
    }
};
