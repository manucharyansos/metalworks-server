<?php

namespace Database\Seeders;

use App\Models\FactoryOrderStatus;
use Illuminate\Database\Seeder;

class FactoryOrderStatusSeeder extends Seeder
{
    private const STATUSES = [
//        [
//            'key'             => 'no_status',
//            'name'            => 'Առանց կարգավիճակի',
//            'value'           => null,
//            'icon'            => 'Circle',
//            'color'           => '#9CA3AF',
//            'requires_reason' => false,
//        ],
        [
            'key'             => 'confirmation',
            'name'            => 'Հաստատել',
            'value'           => 'confirmed',
            'icon'            => 'Check',
            'color'           => '#10B981',
            'requires_reason' => false,
        ],
        [
            'key'             => 'canceling',
            'name'            => 'Մերժել',
            'value'           => 'canceled',
            'icon'            => 'Cross',
            'color'           => '#EF4444',
            'requires_reason' => true,
        ],
        [
            'key'             => 'changeDate',
            'name'            => 'Կատարման ժամկետի փոխարինում',
            'value'           => 'date_changed',
            'icon'            => 'Refresh',
            'color'           => '#F59E0B',
            'requires_reason' => false,
        ],
        [
            'key'             => 'finishing',
            'name'            => 'Ավարտել',
            'value'           => 'finished',
            'icon'            => 'Check Circle',
            'color'           => '#06B6D4',
            'requires_reason' => false,
        ],
    ];

    public function run(): void
    {
        foreach (self::STATUSES as $status) {
            FactoryOrderStatus::updateOrCreate(
                ['key' => $status['key']],
                $status
            );
        }
    }
}
