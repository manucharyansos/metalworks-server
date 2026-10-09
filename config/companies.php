<?php

return [
    'reference_factory_values' => ['INFO', 'PDF'],
    // Authentication identities and the fixed role/permission catalog are shared.
    // Every business record belongs to one company, including child records.
    'tables' => [
        'factories', 'workers', 'clients', 'orders', 'pmps', 'remote_numbers',
        'pmp_files', 'files', 'factory_orders', 'factory_order_files',
        'factory_orders_files', 'selected_files', 'dates', 'order_numbers',
        'prefix_codes', 'store_links', 'details', 'order_logs', 'activity_logs',
        'materials', 'material_groups', 'material_categories', 'material_types',
        'categories', 'file_extensions', 'laser_file_extensions',
        'bend_file_extensions', 'factory_file_extensions', 'factory_order_statuses',
        'statuses', 'laser_cuttings', 'benging_formings',
    ],
];
