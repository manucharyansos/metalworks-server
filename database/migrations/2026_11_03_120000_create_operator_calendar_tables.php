<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('operator_work_intervals', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('starts_at'); $table->dateTime('ends_at'); $table->timestamps();
            $table->index(['company_id', 'operator_id', 'starts_at'], 'work_interval_lookup');
        });
        Schema::create('operator_bookings', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('factory_order_id')->constrained('factory_orders')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users');
            $table->dateTime('starts_at'); $table->dateTime('ends_at'); $table->timestamps();
            $table->index(['company_id', 'operator_id', 'starts_at'], 'operator_booking_lookup');
        });
    }
    public function down(): void { Schema::dropIfExists('operator_bookings'); Schema::dropIfExists('operator_work_intervals'); }
};
