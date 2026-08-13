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

        // Keep deployments backward-compatible if application code is briefly
        // deployed before the new migration has been executed.
        if (!Schema::hasTable('order_number_sequences')) {
            return self::nextFromExistingNumbers($period);
        }

        DB::table('order_number_sequences')->insertOrIgnore([
            'period' => $period,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('order_number_sequences')
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        $existingMax = self::maxExistingSequence($period);
        $next = max((int) ($sequence->last_number ?? 0), $existingMax) + 1;

        DB::table('order_number_sequences')
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
