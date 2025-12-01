<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->enum('type', ['physPerson', 'legalEntity'])
                ->default('physPerson')
                ->change();

            $table->string('AVC', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('type', 20)->change();
            $table->integer('AVC')->nullable()->change();
        });
    }
};
