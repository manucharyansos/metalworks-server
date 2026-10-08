<?php

namespace App\Support;

use App\Models\OrderNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderNumberGenerator
{
    public static function next(): string
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => self::next());
        }

        $period = now()->format('Y-m');
        $companyId = app(CompanyContext::class)->id();
        if (!$companyId && Schema::hasColumn('order_number_sequences', 'company_id')) {
            $companyId = DB::table('companies')->where('slug', 'metalworks')->value('id');
        }

        // Keep deployments backward-compatible if application code is briefly
        // deployed before the new migration has been executed.
        if (!Schema::hasTable('order_number_sequences')) {
            return self::nextFromExistingNumbers($period);
        }

        DB::table('order_number_sequences')->insertOrIgnore([
            ...($companyId ? ['company_id' => $companyId] : []),
            'period' => $period,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('order_number_sequences')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        $existingMax = self::maxExistingSequence($period);
        $next = max((int) ($sequence->last_number ?? 0), $existingMax) + 1;

        DB::table('order_number_sequences')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('period', $period)
            ->update([
                'last_number' => $next,
                'updated_at' => now(),
            ]);

        return sprintf('%s-%04d', $period, $next);
    }

    private static function nextFromExistingNumbers(string $period): string
    {
        return sprintf('%s-%04d', $period, self::maxExistingSequence($period) + 1);
    }

    private static function maxExistingSequence(string $period): int
    {
        return OrderNumber::query()
            ->when(!app(CompanyContext::class)->id() && Schema::hasColumn('order_numbers', 'company_id'), fn ($q) => $q->where('company_id', DB::table('companies')->where('slug', 'metalworks')->value('id')))
            ->where('number', 'like', $period . '-%')
            ->pluck('number')
            ->reduce(function (int $max, string $number) use ($period): int {
                if (!preg_match('/^' . preg_quote($period, '/') . '-(\d+)$/', $number, $matches)) {
                    return $max;
                }

                return max($max, (int) $matches[1]);
            }, 0);
    }
}
