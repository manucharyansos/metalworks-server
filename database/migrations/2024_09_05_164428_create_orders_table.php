<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->string('status')->default('pending');
            $table->boolean('link_existing_files')->default(false);
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('creator_id');
            $table->string('remote_number_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
