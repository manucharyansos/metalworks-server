<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer order requests: a customer uploads parts with their material,
 * thickness, quantity and wanted services, sees an instant estimate from the
 * company's price list, and sends the request; the company answers with the
 * final price and a ready date, and the customer accepts or declines.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The price list for instant estimates: sheet materials, their
        // thicknesses with the cutting price, and the service prices.
        if (!Schema::hasTable('pricing_materials')) Schema::create('pricing_materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('name');
            $table->decimal('density', 8, 1)->default(7850); // kg/m³
            $table->decimal('price_per_kg', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
        if (!Schema::hasTable('pricing_thicknesses')) Schema::create('pricing_thicknesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('pricing_material_id')->constrained('pricing_materials')->cascadeOnDelete();
            $table->decimal('thickness', 6, 2);
            $table->decimal('cut_price_per_m', 12, 2)->default(0);
            $table->decimal('pierce_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['pricing_material_id', 'thickness']);
        });
        if (!Schema::hasTable('pricing_settings')) Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->decimal('bend_price', 12, 2)->default(0);
            $table->decimal('paint_price_m2', 12, 2)->default(0);
            $table->decimal('primer_price_m2', 12, 2)->default(0);
            $table->decimal('paint_min_part', 12, 2)->default(0);
            $table->decimal('setup_fee', 12, 2)->default(0);
            $table->decimal('min_order', 12, 2)->default(0);
            $table->decimal('waste_percent', 5, 2)->default(10);
            $table->json('discounts')->nullable();
            $table->boolean('vat_included')->default(true);
            $table->text('customer_note')->nullable();
            $table->timestamps();
        });

        if (!Schema::hasTable('quote_requests')) Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            // Given when the request is sent; a draft has none.
            $table->string('number', 30)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('title')->nullable();
            $table->text('note')->nullable();
            $table->date('desired_date')->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamp('submitted_at')->nullable();
            // The instant estimate as the customer saw it when sending.
            $table->json('estimate')->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->string('currency', 3)->default('AMD');
            $table->date('ready_date')->nullable();
            $table->text('response_note')->nullable();
            $table->unsignedBigInteger('responded_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id', 'status']);
        });

        if (!Schema::hasTable('quote_request_items')) Schema::create('quote_request_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('quote_request_id')->constrained('quote_requests')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size')->default(0);
            $table->json('workshop_ids')->nullable();
            $table->foreignId('pricing_thickness_id')->nullable()->constrained('pricing_thicknesses')->nullOnDelete();
            // Names as chosen, kept when the price list changes later.
            $table->string('material')->nullable();
            $table->decimal('thickness', 8, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedSmallInteger('bends')->nullable();
            $table->string('paint_ral', 40)->nullable();
            $table->boolean('primer_required')->default(false);
            $table->text('note')->nullable();
            // Measured from the file on upload; the price is kept as sent.
            $table->json('metrics')->nullable();
            $table->json('pricing')->nullable();
            $table->timestamps();
        });

        if (!Schema::hasTable('quote_sequences')) Schema::create('quote_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_request_items');
        Schema::dropIfExists('quote_requests');
        Schema::dropIfExists('quote_sequences');
        Schema::dropIfExists('pricing_thicknesses');
        Schema::dropIfExists('pricing_materials');
        Schema::dropIfExists('pricing_settings');
    }
};
