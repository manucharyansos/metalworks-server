<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class RejectDangerousUploads
{
    private const BLOCKED_EXTENSIONS = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
        'phtml', 'pht', 'phtm', 'phps', 'phar',
        'cgi', 'fcgi', 'pl', 'py', 'rb',
        'sh', 'bash', 'zsh', 'fish',
        'exe', 'com', 'bat', 'cmd', 'msi', 'dll',
        'htaccess', 'htpasswd',
        'html', 'htm', 'xhtml', 'svg',
    ];

    public function handle(Request $request, Closure $next)
    {
        foreach ($this->flattenFiles($request->allFiles()) as $file) {
            $extension = strtolower(trim((string) $file->getClientOriginalExtension()));
            $name = strtolower((string) $file->getClientOriginalName());

            if (
                in_array($extension, self::BLOCKED_EXTENSIONS, true) ||
                $this->hasBlockedTrailingExtension($name)
            ) {
                throw ValidationException::withMessages([
                    'files' => 'Այս ֆայլի տեսակը անվտանգության պատճառով չի թույլատրվում։',
                ]);
            }
        }

        return $next($request);
    }

    /**
     * @return \Generator<int, UploadedFile>
     */
    private function flattenFiles(array $files): \Generator
    {
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                yield $file;
                continue;
            }

            if (is_array($file)) {
                yield from $this->flattenFiles($file);
            }
        }
    }

    private function hasBlockedTrailingExtension(string $name): bool
    {
        $name = rtrim($name, ". \t\n\r\0\x0B");
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, self::BLOCKED_EXTENSIONS, true);
    }
}
