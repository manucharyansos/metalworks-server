<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factory_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('factory_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('pending');
            $table->string('canceling')->default('');
            $table->date('cancel_date')->nullable();
            $table->date('finish_date')->nullable();
            $table->date('operator_finish_date')->nullable();
            $table->date('admin_confirmation_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factory_orders');
    }
};
