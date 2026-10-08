<?php

namespace App\Models\Concerns;

use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $id = app(CompanyContext::class)->id();
            if ($id) $query->where($query->getModel()->qualifyColumn('company_id'), $id);
        });

        static::saving(function ($model): void {
            $context = app(CompanyContext::class);
            $companyId = $context->id();
            // Console tasks and legacy unit fixtures may have no request context.
            // HTTP business routes always require ResolveCompany before bindings.
            if (!$companyId) {
                if (!Schema::hasColumn($model->getTable(), 'company_id')) return;
                $companyId = $model->getAttribute('company_id') ?: DB::table('companies')->where('slug', 'metalworks')->value('id');
            }
            if (!$companyId) throw ValidationException::withMessages(['company_id' => ['Company context is required.']]);
            if (($model->company_id && (int) $model->company_id !== (int) $companyId)
                || ($model->exists && $model->isDirty('company_id'))) {
                throw ValidationException::withMessages(['company_id' => ['Company ownership cannot be changed.']]);
            }
            $model->company_id = $companyId;
            $references = [
                'factory_id' => 'factories', 'order_id' => 'orders', 'pmp_id' => 'pmps',
                'remote_number_id' => 'remote_numbers', 'pmp_file_id' => 'pmp_files',
                'pmp_files_id' => 'pmp_files', 'factory_order_id' => 'factory_orders',
                'material_category_id' => 'material_categories', 'material_group_id' => 'material_groups',
                'material_type_id' => 'material_types', 'material_id' => 'materials',
            ];
            foreach ($references as $field => $table) {
                $value = $model->getAttribute($field);
                if ($value && !DB::table($table)->where('id', $value)->where('company_id', $companyId)->exists()) {
                    throw ValidationException::withMessages([$field => ['The selected record belongs to another company.']]);
                }
            }
            foreach (['user_id', 'creator_id', 'operator_id'] as $field) {
                $value = $model->getAttribute($field);
                if ($value && !DB::table('company_memberships')->where('user_id', $value)->where('company_id', $companyId)->exists()) {
                    throw ValidationException::withMessages([$field => ['The selected account is not assigned to this company.']]);
                }
            }
        });
    }
}
