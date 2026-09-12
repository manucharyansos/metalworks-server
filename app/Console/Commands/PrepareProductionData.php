<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PrepareProductionData extends Command
{
    protected $signature = 'production:clean-start
                            {--force : Required because this permanently removes pre-production business data}';

    protected $description = 'Remove all pre-production business/test data while preserving system configuration and privileged users';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to delete data without --force.');
            return self::FAILURE;
        }

        $adminEmail = trim((string) config('privileged_users.admin.email'));
        $managerEmail = trim((string) config('privileged_users.manager.email'));

        if ($adminEmail === '' || $managerEmail === '') {
            $this->error('ADMIN_EMAIL and MANAGER_EMAIL must be configured before cleanup. Nothing was deleted.');
            return self::FAILURE;
        }

        if (strcasecmp($adminEmail, $managerEmail) === 0) {
            $this->error('ADMIN_EMAIL and MANAGER_EMAIL must be different. Nothing was deleted.');
            return self::FAILURE;
        }

        $this->warn('Deleting pre-production business data and uploaded files...');

        $deletedFiles = $this->deleteStoredFiles();

        $tables = [
            // Order / production history
            'factory_orders_files',
            'factory_order_files',
            'selected_files',
            'order_logs',
            'dates',
            'order_numbers',
            'prefix_codes',
            'store_links',
            'factory_orders',
            'orders',
            'order_number_sequences',
            'details',

            // PMP and uploaded-file metadata
            'pmp_files',
            'remote_numbers',
            'pmps',
            'files',

            // People / customer data
            'workers',
            'clients',
            'visitors',

            // Material catalog entered during development
            'materials',
            'material_categories',
            'material_groups',
            'material_types',
            'categories',

            // Legacy production tables
            'laser_cuttings',
            'benging_formings',
            'statuses',

            // Legacy extension catalogs; current rules are rebuilt below
            'file_extensions',
            'laser_file_extensions',
            'bend_file_extensions',
            'factory_file_extensions',

            // Current system statuses are rebuilt below
            'factory_order_statuses',

            // Per-user/session/test runtime data
            'permission_user',
            'personal_access_tokens',
            'password_reset_tokens',
            'failed_jobs',
            'sessions',
        ];

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::table($table)->truncate();
                $this->line("Cleared {$table}");
            }

            if (Schema::hasTable('users')) {
                $deletedUsers = DB::table('users')
                    ->whereNotIn(DB::raw('LOWER(email)'), [
                        strtolower($adminEmail),
                        strtolower($managerEmail),
                    ])
                    ->delete();

                $this->line("Removed {$deletedUsers} non-privileged users");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info("Deleted {$deletedFiles} stored file copies.");
        $this->info('Rebuilding required production system data...');

        $exitCode = Artisan::call('db:seed', ['--force' => true]);
        $seedOutput = trim(Artisan::output());

        if ($seedOutput !== '') {
            $this->line($seedOutput);
        }

        if ($exitCode !== self::SUCCESS) {
            $this->error('Cleanup completed, but system seeding failed. Check the output above.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Production data is clean. Only privileged users and required system catalogs remain.');

        return self::SUCCESS;
    }

    private function deleteStoredFiles(): int
    {
        $paths = [];

        foreach (['pmp_files', 'files', 'factory_order_files'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'path')) {
                continue;
            }

            foreach (DB::table($table)->whereNotNull('path')->pluck('path') as $path) {
                $normalized = $this->normalizePath((string) $path);
                if ($normalized !== null) {
                    $paths[$normalized] = true;
                }
            }
        }

        $deleted = 0;

        foreach (array_keys($paths) as $path) {
            foreach (['private', 'public'] as $disk) {
                if (Storage::disk($disk)->exists($path)) {
                    if (Storage::disk($disk)->delete($path)) {
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    private function normalizePath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', urldecode($path)), '/');

        if ($path === '' || $path === '..' || str_contains($path, '../')) {
            return null;
        }

        return $path;
    }
}
