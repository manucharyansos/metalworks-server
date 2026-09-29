<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 60)->index();
            $table->string('action', 150)->index();
            $table->string('method', 10)->nullable();
            $table->string('route')->nullable();
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->string('subject_label')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['category', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // Preserve the existing admin history. From this migration onward new
        // activity is written to activity_logs, while the old order timeline
        // remains available as historical data here.
        if (Schema::hasTable('order_logs')) {
            DB::table('order_logs')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $payload = [];

                    foreach ($rows as $row) {
                        $action = (string) ($row->action ?? 'order.updated');
                        $payload[] = [
                            'user_id' => $row->user_id,
                            'category' => str_starts_with($action, 'factory_order.') ? 'production' : 'orders',
                            'action' => $action,
                            'method' => null,
                            'route' => 'legacy:order_logs',
                            'subject_type' => 'order',
                            'subject_id' => (string) $row->order_id,
                            'subject_label' => null,
                            'description' => $row->message,
                            'meta' => $row->meta,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ];
                    }

                    if ($payload !== []) {
                        DB::table('activity_logs')->insert($payload);
                    }
                }, 'id');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
