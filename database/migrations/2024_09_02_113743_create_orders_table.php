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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->unsignedBigInteger('description_id');
            $table->unsignedBigInteger('creator_id');
            $table->unsignedBigInteger('prefix_code_id');
            $table->unsignedBigInteger('store_link_id');
            $table->unsignedBigInteger('status_id')->default(1);
            $table->foreign('description_id')->references('id')->on('descriptions')->onDelete('cascade');
            $table->foreign('creator_id')->references('id')->on('creators')->onDelete('cascade');
            $table->foreign('prefix_code_id')->references('id')->on('prefix_codes')->onDelete('cascade');
            $table->foreign('store_link_id')->references('id')->on('store_links')->onDelete('cascade');
            $table->foreign('status_id')->references('id')->on('statuses')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
