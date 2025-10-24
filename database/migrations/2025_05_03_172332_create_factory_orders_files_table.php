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
        Schema::create('factory_orders_files', function (Blueprint $table) {
            $table->foreignId('factory_order_id')->constrained();
            $table->foreignId('pmp_file_id')->constrained('pmp_files');
            $table->integer('quantity')->default(1); 
            $table->timestamps();
            $table->primary(['factory_order_id', 'pmp_file_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('factory_orders_files');
    }
};
