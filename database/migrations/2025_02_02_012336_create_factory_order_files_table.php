<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('factory_order_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_order_id')->constrained('factory_orders')->onDelete('cascade');
            $table->string('path');
            $table->string('original_name');
            $table->integer('quantity')->nullable();
            $table->string('material_type')->nullable();
            $table->string('thickness', 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('factory_order_files');
    }
};
