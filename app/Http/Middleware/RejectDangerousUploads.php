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
            $originalName = (string) $file->getClientOriginalName();
            $normalizedName = strtolower($originalName);
            $extension = strtolower(trim((string) $file->getClientOriginalExtension()));

            if ($this->hasUnsafeName($originalName)) {
                throw ValidationException::withMessages([
                    'files' => 'Ֆայլի անունը անվտանգության պատճառով չի թույլատրվում։',
                ]);
            }

            if (
                in_array($extension, self::BLOCKED_EXTENSIONS, true) ||
                $this->hasBlockedExtensionSegment($normalizedName)
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

    private function hasUnsafeName(string $name): bool
    {
        if ($name === '' || strlen($name) > 255) {
            return true;
        }

        if (
            str_contains($name, '/') ||
            str_contains($name, '\\') ||
            str_contains($name, "\0") ||
            $name === '.' ||
            $name === '..'
        ) {
            return true;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $name) === 1;
    }

    private function hasBlockedExtensionSegment(string $name): bool
    {
        $name = rtrim($name, ". \t\n\r\0\x0B");
        $segments = explode('.', $name);

        if (count($segments) <= 1) {
            return false;
        }

        array_shift($segments);

        foreach ($segments as $segment) {
            $segment = strtolower(trim($segment));
            if (in_array($segment, self::BLOCKED_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }
}
