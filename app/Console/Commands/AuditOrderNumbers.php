<?php

namespace App\Console\Commands;

use App\Models\OrderNumber;
use Illuminate\Console\Command;

class AuditOrderNumbers extends Command
{
    protected $signature = 'order-numbers:audit';

    protected $description = 'Check order numbers for duplicates or malformed values before production hardening';

    public function handle(): int
    {
        $duplicates = OrderNumber::query()
            ->select('number')
            ->selectRaw('COUNT(*) as duplicate_count')
            ->groupBy('number')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('number')
            ->get();

        $malformed = OrderNumber::query()
            ->pluck('number')
            ->filter(fn ($number) => !is_string($number) || !preg_match('/^\d{4}-\d{2}-\d+$/', $number))
            ->values();

        if ($duplicates->isEmpty() && $malformed->isEmpty()) {
            $this->info('Order number audit passed: no duplicates or malformed values found.');
            return self::SUCCESS;
        }

        if ($duplicates->isNotEmpty()) {
            $this->error('Duplicate order numbers found:');
            $this->table(
                ['number', 'count'],
                $duplicates->map(fn ($row) => [$row->number, $row->duplicate_count])->all()
            );
        }

        if ($malformed->isNotEmpty()) {
            $this->error('Malformed order numbers found:');
            foreach ($malformed as $number) {
                $this->line((string) $number);
            }
        }

        return self::FAILURE;
    }
}
