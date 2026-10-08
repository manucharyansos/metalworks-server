<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PrivatizeWorkspaceFiles extends Command
{
    protected $signature = 'files:privatize-workspaces {--apply : Copy and verify files, then remove matching public copies}';
    protected $description = 'Move all enterprise uploads into private storage after SHA-256 verification';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('private');
        $apply = (bool) $this->option('apply');
        $paths = [];
        foreach (['files' => 'path', 'pmp_files' => 'path', 'factory_order_files' => 'path', 'materials' => 'image', 'material_groups' => 'image'] as $table => $column) {
            if (!Schema::hasColumn($table, $column)) continue;
            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $path) {
                if ($path !== '') $paths[$path] = true;
            }
        }
        // Include abandoned uploads in known business directories; leave
        // unrelated public website assets in place.
        foreach ($public->directories() as $directory) {
            if (!in_array($directory, ['orders', 'materials', 'categories', 'companies'], true) && !str_starts_with($directory, 'PMP_')) continue;
            foreach ($public->allFiles($directory) as $path) $paths[$path] = true;
        }
        $failed = 0; $moved = 0;
        foreach (array_keys($paths) as $path) {
            if (str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('~(^|/)\.\.?(?:/|$)~', $path)) {
                $this->error("Unsafe path: {$path}"); $failed++; continue;
            }
            if (!$public->exists($path)) {
                if (!$private->exists($path)) { $this->error("Missing upload: {$path}"); $failed++; }
                continue;
            }
            if (!$apply) { $this->line("[dry run] {$path}"); continue; }
            try {
                if (!$private->exists($path)) {
                    $stream = $public->readStream($path);
                    try { $written = is_resource($stream) && $private->writeStream($path, $stream); }
                    finally { if (is_resource($stream)) fclose($stream); }
                    if (!$written) throw new \RuntimeException('Copy failed');
                }
                $sourceHash = hash_file('sha256', $public->path($path));
                $targetHash = hash_file('sha256', $private->path($path));
                if (!$sourceHash || !$targetHash || !hash_equals($sourceHash, $targetHash)) throw new \RuntimeException('SHA-256 mismatch; public copy preserved');
                if (!$public->delete($path)) throw new \RuntimeException('Could not remove verified public copy');
                $moved++;
            } catch (\Throwable $error) {
                $this->error("{$path}: {$error->getMessage()}"); $failed++;
            }
        }
        $this->info(($apply ? "Privatized {$moved} uploads" : 'Dry run completed') . "; {$failed} issues.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
