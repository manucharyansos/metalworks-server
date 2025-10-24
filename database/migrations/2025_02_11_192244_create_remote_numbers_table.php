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
        Schema::create('remote_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('remote_number', 2);
            $table->string('remote_number_name');
            $table->foreignId('pmp_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->unique(['remote_number', 'pmp_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remote_numbers');
    }
};
