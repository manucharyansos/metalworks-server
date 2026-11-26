<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('quote_request_items', function (Blueprint $table) {
            $table->uuid('assembly_key')->nullable()->index();
            $table->unsignedTinyInteger('assembly_position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quote_request_items', function (Blueprint $table) {
            $table->dropIndex(['assembly_key']);
            $table->dropColumn(['assembly_key', 'assembly_position']);
        });
    }
};
