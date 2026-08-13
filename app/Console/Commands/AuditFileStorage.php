<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\PmpFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditFileStorage extends Command
{
    protected $signature = 'files:security-audit {--limit=20 : Maximum sample rows to print per category}';

    protected $description = 'Read-only audit of DB-backed files across public, private, and missing storage';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 100));

        $this->info('Scanning PMP files...');
        $pmp = $this->scan(PmpFiles::query()->select(['id', 'path'])->cursor(), 'pmp');

        $this->info('Scanning order files...');
        $orders = $this->scan(File::query()->select(['id', 'path'])->cursor(), 'order');

        $totals = [
            'records' => $pmp['records'] + $orders['records'],
            'private' => $pmp['private'] + $orders['private'],
            'public' => $pmp['public'] + $orders['public'],
            'both' => $pmp['both'] + $orders['both'],
            'missing' => $pmp['missing'] + $orders['missing'],
            'unsafe_path' => $pmp['unsafe_path'] + $orders['unsafe_path'],
        ];

        $this->newLine();
        $this->table(
            ['scope', 'records', 'private', 'public', 'both', 'missing', 'unsafe path'],
            [
                ['PMP', $pmp['records'], $pmp['private'], $pmp['public'], $pmp['both'], $pmp['missing'], $pmp['unsafe_path']],
                ['Order', $orders['records'], $orders['private'], $orders['public'], $orders['both'], $orders['missing'], $orders['unsafe_path']],
                ['TOTAL', $totals['records'], $totals['private'], $totals['public'], $totals['both'], $totals['missing'], $totals['unsafe_path']],
            ]
        );

        $samples = array_slice(array_merge($pmp['samples'], $orders['samples']), 0, $limit);
        if ($samples) {
            $this->newLine();
            $this->warn('Samples requiring attention:');
            $this->table(['scope', 'id', 'state', 'path'], $samples);
        }

        $this->newLine();
        if ($totals['missing'] > 0 || $totals['unsafe_path'] > 0) {
            $this->error('Audit found missing files or unsafe stored paths. No data was changed.');
            return self::FAILURE;
        }

        if ($totals['public'] > 0 || $totals['both'] > 0) {
            $this->warn('Audit completed: DB-backed files still exist on public storage. No data was changed.');
            return self::SUCCESS;
        }

        $this->info('Audit passed: all DB-backed files are private and present.');
        return self::SUCCESS;
    }

    private function scan(iterable $records, string $scope): array
    {
        $result = [
            'records' => 0,
            'private' => 0,
            'public' => 0,
            'both' => 0,
            'missing' => 0,
            'unsafe_path' => 0,
            'samples' => [],
        ];

        foreach ($records as $record) {
            $result['records']++;
            $path = $this->normalizePath((string) $record->path);

            if ($path === null) {
                $result['unsafe_path']++;
                $result['samples'][] = [$scope, $record->id, 'unsafe_path', (string) $record->path];
                continue;
            }

            $private = Storage::disk('private')->exists($path);
            $public = Storage::disk('public')->exists($path);

            if ($private && $public) {
                $result['both']++;
                $result['samples'][] = [$scope, $record->id, 'both', $path];
            } elseif ($private) {
                $result['private']++;
            } elseif ($public) {
                $result['public']++;
                $result['samples'][] = [$scope, $record->id, 'public', $path];
            } else {
                $result['missing']++;
                $result['samples'][] = [$scope, $record->id, 'missing', $path];
            }
        }

        return $result;
    }

    private function normalizePath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', urldecode($path)), '/');

        if ($path === '' || $path === '..' || str_contains($path, '../')) {
            return null;
        }

        return $path;
    }
}
