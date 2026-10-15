<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class TaskStages
{
    public const STAGES = ['created', 'accepted', 'in_progress', 'completed'];

    /** One stage per task, shared by list filtering and counts before pagination. */
    public static function sql(): string
    {
        // Match Factory::is_reference, including built-in reference values in newly seeded companies.
        $referenceValues = implode(',', array_map(fn ($value) => DB::getPdo()->quote(strtoupper($value)), config('companies.reference_factory_values', ['INFO', 'PDF'])));
        $production = $referenceValues ? "AND (f.value IS NULL OR UPPER(f.value) NOT IN ($referenceValues))" : '';
        $work = "s.order_id = orders.id AND s.company_id = orders.company_id AND EXISTS (SELECT 1 FROM factories f WHERE f.id = s.factory_id AND f.company_id = s.company_id AND f.is_reference = 0 $production)";
        $active = "$work AND (s.status IS NULL OR s.status NOT IN ('canceled', 'cancelled'))";
        $done = "(s.completed_at IS NOT NULL OR (COALESCE(s.status, '') IN ('finished', 'completed', 'done') AND (s.confirmation_required = 0 OR s.engineer_confirmation_at IS NOT NULL)))";
        return "CASE
            WHEN orders.status = 'completed' OR (EXISTS (SELECT 1 FROM factory_orders s WHERE $active)
                AND NOT EXISTS (SELECT 1 FROM factory_orders s WHERE $active AND NOT $done)) THEN 'completed'
            WHEN EXISTS (SELECT 1 FROM factory_orders s WHERE $active AND (s.status IN ('confirmed', 'in_progress', 'date_changed', 'finished', 'completed', 'done') OR s.completed_at IS NOT NULL)) THEN 'in_progress'
            WHEN EXISTS (SELECT 1 FROM factory_orders s WHERE $active AND s.status = 'accepted') THEN 'accepted'
            ELSE 'created' END";
    }
}
