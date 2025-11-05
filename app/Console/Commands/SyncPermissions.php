<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Permission;

class SyncPermissions extends Command
{
    protected $signature = 'permission:sync';
    protected $description = 'Sync permissions from config/permissions.php to database';

    public function handle()
    {
        $permissions = config('permissions');

        foreach ($permissions as $group => $items) {
            foreach ($items as $slug => $name) {
                Permission::updateOrCreate(
                    ['slug' => "{$group}.{$slug}"],
                    ['name' => $name, 'group' => $group]
                );
            }
        }

        $this->info('✅ Permissions synced successfully from config.');
    }
}
