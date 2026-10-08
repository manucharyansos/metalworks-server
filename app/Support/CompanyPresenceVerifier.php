<?php

namespace App\Support;

use Illuminate\Validation\DatabasePresenceVerifier;

class CompanyPresenceVerifier extends DatabasePresenceVerifier
{
    private function companyConditions(string $table, string $column, array $extra): array
    {
        $id = app(CompanyContext::class)->id();
        if (!$id) return $extra;
        if (in_array($table, config('companies.tables'), true)) $extra['company_id'] = $id;
        if ($table === 'users' && $column === 'id') {
            $extra['company_membership'] = fn ($query) => $query->whereIn('id', function ($q) use ($id) {
                $q->select('user_id')->from('company_memberships')->where('company_id', $id)->where('is_active', true);
            });
        }
        return $extra;
    }

    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, $extra = [])
    {
        return parent::getCount($collection, $column, $value, $excludeId, $idColumn, $this->companyConditions($collection, $column, $extra));
    }

    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        return parent::getMultiCount($collection, $column, $values, $this->companyConditions($collection, $column, $extra));
    }
}
