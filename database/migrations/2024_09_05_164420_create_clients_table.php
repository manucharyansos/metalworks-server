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
        Schema::create('clients', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name')->nullable();
            $table->string('number')->nullable();
            $table->string('AVC')->nullable();
            $table->string('group')->nullable();
            $table->boolean('VAT_payer')->nullable()->default(false);
            $table->string('legal_address')->nullable();
            $table->string('valid_address')->nullable();
            $table->string('VAT_of_the_manager')->nullable();
            $table->string('leadership_position')->nullable();
            $table->string('accountants_VAT')->nullable();
            $table->string('accountant_position')->nullable();
            $table->string('registration_of_the_individual')->nullable();
            $table->string('type_of_ID_card')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('email_address')->nullable();
//            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('contract')->nullable();
            $table->date('contract_date')->nullable();
            $table->string('sales_discount_percentage')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
