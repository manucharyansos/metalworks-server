<?php

namespace Database\Seeders;

use App\Models\FactoryOrderStatus;
use Illuminate\Database\Seeder;

class FactoryOrderStatusSeeder extends Seeder
{
    private const STATUSES = [
        [
            'key'             => 'confirmation',
            'name'            => 'Կատարել',
            'status_label'    => 'Կատարվում է',
            'value'           => 'confirmed',
            'icon'            => 'Check',
            'color'           => '#10B981',
            'requires_reason' => false,
        ],
        [
            'key'             => 'canceling',
            'name'            => 'Մերժել',
            'status_label'    => 'Մերժված',
            'value'           => 'canceled',
            'icon'            => 'Cross',
            'color'           => '#EF4444',
            'requires_reason' => true,
        ],
        [
            'key'             => 'changeDate',
            'name'            => 'Կատարման ժամկետի փոխարինում',
            'status_label'    => 'Ժամկետը փոխված է',
            'value'           => 'date_changed',
            'icon'            => 'Refresh',
            'color'           => '#F59E0B',
            'requires_reason' => false,
        ],
        [
            'key'             => 'finishing',
            'name'            => 'Ավարտել',
            'status_label'    => 'Ավարտված',
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
