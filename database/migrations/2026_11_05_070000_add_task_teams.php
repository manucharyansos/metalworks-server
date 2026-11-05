<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('factory_orders', 'task_scoped_operator')) Schema::table('factory_orders', fn (Blueprint $t) => $t->boolean('task_scoped_operator')->default(false));
        if (!Schema::hasTable('factory_order_helpers')) Schema::create('factory_order_helpers', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('factory_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unique(['factory_order_id', 'user_id']); $t->timestamps();
        });
        if (!Schema::hasColumn('operator_bookings', 'primary_booking_id')) Schema::table('operator_bookings', function (Blueprint $t) {
            $t->foreignId('primary_booking_id')->nullable()->constrained('operator_bookings')->cascadeOnDelete();
        });
    }
    public function down(): void {
        Schema::table('operator_bookings', fn (Blueprint $t) => $t->dropConstrainedForeignId('primary_booking_id'));
        Schema::dropIfExists('factory_order_helpers');
        Schema::table('factory_orders', fn (Blueprint $t) => $t->dropColumn('task_scoped_operator'));
    }
};
