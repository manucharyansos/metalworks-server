<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\PmpFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class StageFilesPrivate extends Command
{
    protected $signature = 'files:stage-private
        {--apply : Actually copy eligible files to private storage; without this flag the command is read-only}
        {--limit=0 : Maximum number of files to process; 0 means all}';

    protected $description = 'Safely stage DB-backed public files into private storage without deleting public copies';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $processed = 0;
        $eligible = 0;
        $copied = 0;
        $alreadyPrivate = 0;
        $missing = 0;
        $unsafe = 0;
        $failed = 0;

        if (!$apply) {
            $this->warn('DRY RUN: no files will be copied. Use --apply only after reviewing this output.');
        } else {
            $this->warn('APPLY MODE: eligible files will be COPIED to private storage. Public copies will NOT be deleted.');
        }

        foreach ($this->records() as [$scope, $record]) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;

            $path = $this->normalizePath((string) $record->path);
            if ($path === null) {
                $unsafe++;
                $this->error("[{$scope}:{$record->id}] unsafe path: {$record->path}");
                continue;
            }

            $hasPrivate = Storage::disk('private')->exists($path);
            $hasPublic = Storage::disk('public')->exists($path);

            if ($hasPrivate) {
                $alreadyPrivate++;
                continue;
            }

            if (!$hasPublic) {
                $missing++;
                $this->error("[{$scope}:{$record->id}] missing: {$path}");
                continue;
            }

            $eligible++;
            if (!$apply) {
                $this->line("[dry-run] {$scope}:{$record->id} {$path}");
                continue;
            }

            $stream = Storage::disk('public')->readStream($path);
            if ($stream === false) {
                $failed++;
                $this->error("[{$scope}:{$record->id}] could not read: {$path}");
                continue;
            }

            try {
                $ok = Storage::disk('private')->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (!$ok || !Storage::disk('private')->exists($path)) {
                $failed++;
                $this->error("[{$scope}:{$record->id}] copy verification failed: {$path}");
                continue;
            }

            $copied++;
            $this->info("[copied] {$scope}:{$record->id} {$path}");
        }

        $this->newLine();
        $this->table(
            ['processed', 'eligible', 'copied', 'already private', 'missing', 'unsafe', 'failed'],
            [[
                $processed,
                $eligible,
                $copied,
                $alreadyPrivate,
                $missing,
                $unsafe,
                $failed,
            ]]
        );

        if ($missing > 0 || $unsafe > 0 || $failed > 0) {
            $this->error('Staging completed with issues. Public files were never deleted.');
            return self::FAILURE;
        }

        if (!$apply) {
            $this->info('Dry run completed. No data or files were changed.');
        } else {
            $this->info('Private staging completed. Public copies were preserved for rollback compatibility.');
        }

        return self::SUCCESS;
    }

    private function records(): \Generator
    {
        foreach (PmpFiles::query()->select(['id', 'path'])->cursor() as $record) {
            yield ['pmp', $record];
        }

        foreach (File::query()->select(['id', 'path'])->cursor() as $record) {
            yield ['order', $record];
        }
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
