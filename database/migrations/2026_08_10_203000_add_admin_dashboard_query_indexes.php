<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'orders_status_created_idx');
        });

        Schema::table('dates', function (Blueprint $table) {
            $table->index(['finish_date', 'order_id'], 'dates_finish_order_idx');
        });

        Schema::table('factory_orders', function (Blueprint $table) {
            $table->index(
                ['status', 'admin_confirmation_date'],
                'fo_status_confirmation_idx'
            );
            $table->index(
                ['factory_id', 'status', 'admin_confirmation_date'],
                'fo_factory_status_confirm_idx'
            );
            $table->index(
                ['operator_id', 'status', 'admin_confirmation_date'],
                'fo_operator_status_confirm_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('factory_orders', function (Blueprint $table) {
            $table->dropIndex('fo_operator_status_confirm_idx');
            $table->dropIndex('fo_factory_status_confirm_idx');
            $table->dropIndex('fo_status_confirmation_idx');
        });

        Schema::table('dates', function (Blueprint $table) {
            $table->dropIndex('dates_finish_order_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_created_idx');
        });
    }
};
