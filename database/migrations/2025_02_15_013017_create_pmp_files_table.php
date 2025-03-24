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
        Schema::create('pmp_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pmp_id');
            $table->unsignedBigInteger('remote_number_id');
            $table->unsignedBigInteger('factory_id');
            $table->string('path');
            $table->string('original_name');
            $table->integer('quantity')->nullable();
            $table->string('material_type')->nullable();
            $table->string('thickness', 8)->nullable();
            $table->timestamps();

            $table->foreign('pmp_id')->references('id')->on('pmps')->onDelete('cascade');
            $table->foreign('remote_number_id')->references('id')->on('remote_numbers')->onDelete('cascade');
            $table->foreign('factory_id')->references('id')->on('factories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pmp_files');
    }
};
